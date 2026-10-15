<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessDelegation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Kelola role & permission-nya (dijaga role.manage di route).
 *
 * - Role sistem (admin, employee) terkunci: kode bergantung pada keduanya.
 * - Key role tak bisa diganti setelah dibuat — users.role menyimpannya.
 * - Pengelola non-admin hanya boleh memberi permission yang ia punya sendiri
 *   dan tak boleh menyunting role miliknya sendiri (AccessDelegation).
 */
class RoleController extends Controller
{
    public function index(Request $request)
    {
        return static::listFor($request->user());
    }

    /** Daftar role + permission + jumlah pemakai, dipakai juga /settings. */
    public static function listFor(User $viewer)
    {
        $delegation = AccessDelegation::for($viewer);
        $counts = User::selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role');

        return Role::orderByDesc('is_system')->orderBy('name')->get()->map(fn (Role $r) => [
            'id' => $r->id,
            'key' => $r->key,
            'name' => $r->name,
            'description' => $r->description,
            'is_system' => $r->is_system,
            'permissions' => $r->permissions(),
            'full_data_access' => $r->key === Role::ADMIN || in_array('data.all', $r->permissions(), true),
            'users_count' => (int) ($counts[$r->key] ?? 0),
            // Boleh dipakai viewer saat membuat/mengubah user & boleh disunting?
            'assignable' => $delegation->canAssignRole($r->key),
            'editable' => ! $r->is_system && $delegation->canEditRole($r),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:50', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:roles,key'],
            ...$this->rules(),
        ], [
            'key.regex' => 'Key hanya huruf kecil, angka, dan underscore, diawali huruf.',
        ]);

        $this->guardGrant($request, $data['permissions']);

        $role = Role::create([
            'key' => $data['key'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_system' => false,
        ]);
        $role->syncPermissions($data['permissions']);

        AuditLog::record('role.created', $role, ['permissions' => $role->permissions()]);

        return response()->json($this->find($request, $role), 201);
    }

    public function update(Request $request, string $id)
    {
        $role = Role::findOrFail($id);
        $this->guardEdit($request, $role);

        $data = $request->validate($this->rules());
        $this->guardGrant($request, $data['permissions']);

        $before = [...$role->only(['name', 'description']), 'permissions' => $role->permissions()];

        $role->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);
        $role->syncPermissions($data['permissions']);

        $changes = AuditLog::diff($before, [...$role->only(['name', 'description']), 'permissions' => $role->permissions()]);
        if ($changes) {
            AuditLog::record('role.updated', $role, $changes);
        }

        return response()->json($this->find($request, $role));
    }

    public function destroy(Request $request, string $id)
    {
        $role = Role::findOrFail($id);
        $this->guardEdit($request, $role);

        if ($role->users()->exists()) {
            return response()->json([
                'message' => 'Role masih dipakai user. Pindahkan user ke role lain dulu.',
            ], 422);
        }

        AuditLog::record('role.deleted', $role, ['permissions' => $role->permissions()]);

        $role->delete();

        return response()->json(['success' => true]);
    }

    private function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'permissions' => 'present|array',
            'permissions.*' => ['string', Rule::in(Role::assignablePermissions())],
        ];
    }

    private function guardEdit(Request $request, Role $role): void
    {
        if ($role->is_system) {
            throw new AccessDeniedHttpException('Role sistem tidak bisa diubah atau dihapus.');
        }

        if (! AccessDelegation::for($request->user())->canEditRole($role)) {
            throw new AccessDeniedHttpException('Anda tidak boleh mengubah role ini.');
        }
    }

    private function guardGrant(Request $request, array $permissions): void
    {
        if (! AccessDelegation::for($request->user())->canGrant($permissions)) {
            throw new AccessDeniedHttpException('Anda tidak boleh memberikan permission yang tidak Anda miliki.');
        }
    }

    private function find(Request $request, Role $role): array
    {
        return static::listFor($request->user())->firstWhere('id', $role->id);
    }
}
