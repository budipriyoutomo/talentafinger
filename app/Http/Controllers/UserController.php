<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use App\Models\UserScope;
use App\Services\AccessDelegation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Manajemen user, role, dan SCOPE (Company/Brand/Outlet yang jadi wewenangnya).
 * Dijaga permission user.manage (route) + AccessDelegation: pengelola non-admin
 * hanya boleh memberi role & scope yang masih di dalam haknya sendiri — ini
 * pintu yang bisa dipakai memperluas hak akses diri sendiri.
 */
class UserController extends Controller
{
    public function index(Request $request)
    {
        return static::listFor($request->user());
    }

    /**
     * Daftar user yang boleh dikelola $viewer (admin: semua), dipakai juga oleh
     * halaman /settings. Scope ikut dikirim supaya form bisa mencentang ulang.
     */
    public static function listFor(User $viewer)
    {
        $delegation = AccessDelegation::for($viewer);

        return User::with('dataScopes')->orderBy('name')->get()
            ->filter(fn (User $u) => $delegation->canManageUser($u))
            ->map(fn (User $u) => static::present($u))
            ->values();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'required|email|unique:users,email',
            'password' => ['required', 'string', Password::defaults()],
            'role' => ['required', 'string', 'exists:roles,key'],
            'is_active' => 'sometimes|boolean',
            ...$this->employeeRules(),
            ...$this->scopeRules(),
        ]);

        $this->guardDelegation($request, null, $data);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            // Password dari admin = sementara; pemilik akun wajib menggantinya
            // saat login pertama, supaya admin tak tahu password akhirnya.
            'must_change_password' => true,
            'role' => $data['role'],
            'is_active' => $data['is_active'] ?? true,
            'employee_id' => $data['role'] === Role::EMPLOYEE ? $data['employee_id'] : null,
        ]);

        $this->syncScopes($user, $data);

        AuditLog::record('user.created', $user, $this->auditState($user));

        return response()->json(static::present($user->fresh('dataScopes')), 201);
    }

    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:150',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($id)],
            // Password opsional saat edit: kosong = jangan ubah.
            'password' => ['nullable', 'string', Password::defaults()],
            'role' => ['required', 'string', 'exists:roles,key'],
            'is_active' => 'sometimes|boolean',
            ...$this->employeeRules($id),
            ...$this->scopeRules(),
        ]);

        $this->guardDelegation($request, $user, $data);

        $deactivating = array_key_exists('is_active', $data) && ! $data['is_active'];

        if ($deactivating && $user->id === $request->user()->id) {
            return response()->json(['message' => 'Tidak bisa menonaktifkan akun yang sedang login.'], 422);
        }

        // Jangan biarkan admin AKTIF terakhir diturunkan / dinonaktifkan,
        // supaya aplikasi tak terkunci tanpa admin.
        if ($this->isLastActiveAdmin($user) && ($data['role'] !== Role::ADMIN || $deactivating)) {
            return response()->json(['message' => 'Tidak bisa menurunkan / menonaktifkan admin aktif terakhir. Buat admin lain dulu.'], 422);
        }

        $before = $this->auditState($user);

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->role = $data['role'];
        if (array_key_exists('is_active', $data)) {
            $user->is_active = $data['is_active'];
        }
        // Tautan karyawan hanya bermakna untuk role employee.
        $user->employee_id = $data['role'] === Role::EMPLOYEE ? $data['employee_id'] : null;
        // Reset password oleh admin: sesi lama user itu otomatis keluar
        // (AuthenticateSession) dan ia wajib mengganti password sementara ini.
        // Admin yang mengganti password-nya SENDIRI di sini tidak dipaksa.
        if (filled($data['password'] ?? null)) {
            $user->password = Hash::make($data['password']);
            $user->must_change_password = $user->id !== $request->user()->id;
        }
        $user->save();

        $this->syncScopes($user, $data);

        $changes = AuditLog::diff($before, $this->auditState($user->fresh()));
        if (filled($data['password'] ?? null)) {
            // Nilainya tak pernah dicatat — cukup faktanya.
            $changes['password'] = ['from' => null, 'to' => 'direset'];
        }
        if ($changes) {
            AuditLog::record('user.updated', $user, $changes);
        }

        return response()->json(static::present($user->fresh('dataScopes')));
    }

    private function isLastActiveAdmin(User $user): bool
    {
        return $user->isAdmin() && $user->is_active
            && User::where('role', Role::ADMIN)->where('is_active', true)->count() <= 1;
    }

    /**
     * Potret field user yang relevan untuk audit. Scope ditulis sebagai NAMA
     * (bukan UUID) supaya log terbaca tanpa menelusuri database.
     */
    private function auditState(User $user): array
    {
        $scope = $user->directScopeIds();

        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_active' => (bool) $user->is_active,
            'employee' => $user->employee_id ? Employee::whereKey($user->employee_id)->value('name') : null,
            'company' => Company::whereIn('id', $scope['company'])->orderBy('name')->pluck('name')->all(),
            'brand' => Brand::whereIn('id', $scope['brand'])->orderBy('name')->pluck('name')->all(),
            'outlet' => Outlet::whereIn('id', $scope['outlet'])->orderBy('name')->pluck('name')->all(),
        ];
    }

    public function destroy(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        if ((int) $id === (int) $request->user()->id) {
            return response()->json(['message' => 'Tidak bisa menghapus akun yang sedang login.'], 422);
        }

        $this->guardDelegation($request, $user);

        if ($this->isLastActiveAdmin($user)) {
            return response()->json(['message' => 'Tidak bisa menghapus admin aktif terakhir.'], 422);
        }

        AuditLog::record('user.deleted', $user, $this->auditState($user));

        // user_scopes ikut terhapus lewat FK cascade.
        $user->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Pagar anti-eskalasi (lihat AccessDelegation): user sasaran harus berada
     * dalam hak aktor, dan role / karyawan / scope yang diberikan juga.
     */
    private function guardDelegation(Request $request, ?User $target, array $data = []): void
    {
        $delegation = AccessDelegation::for($request->user());

        if ($target && ! $delegation->canManageUser($target)) {
            throw new AccessDeniedHttpException('User ini berada di luar wewenang Anda.');
        }

        if (! $data) {
            return;
        }

        if (! $delegation->canAssignRole($data['role'])) {
            throw new AccessDeniedHttpException('Anda tidak boleh memberikan role ini.');
        }

        if ($data['role'] === Role::EMPLOYEE && ! $delegation->canLinkEmployee($data['employee_id'] ?? null)) {
            throw new AccessDeniedHttpException('Karyawan ini berada di luar wewenang Anda.');
        }

        if (! $delegation->canAssignScopes(
            $data['company_ids'] ?? [], $data['brand_ids'] ?? [], $data['outlet_ids'] ?? [],
        )) {
            throw new AccessDeniedHttpException('Ada Company/Brand/Outlet di luar wewenang Anda.');
        }
    }

    /**
     * Akun ber-role `employee` wajib ditautkan ke tepat satu karyawan, dan satu
     * karyawan hanya boleh punya satu akun.
     */
    private function employeeRules(?string $ignoreUserId = null): array
    {
        return [
            'employee_id' => [
                'nullable',
                'required_if:role,'.Role::EMPLOYEE,
                'uuid',
                'exists:employees,id',
                Rule::unique('users', 'employee_id')->ignore($ignoreUserId),
            ],
        ];
    }

    /** Scope dikirim sebagai tiga daftar terpisah supaya bisa divalidasi `exists`. */
    private function scopeRules(): array
    {
        return [
            'company_ids' => 'sometimes|array',
            'company_ids.*' => 'uuid|exists:companies,id',
            'brand_ids' => 'sometimes|array',
            'brand_ids.*' => 'uuid|exists:brands,id',
            'outlet_ids' => 'sometimes|array',
            'outlet_ids.*' => 'uuid|exists:outlets,id',
        ];
    }

    private function syncScopes(User $user, array $data): void
    {
        // Tak satu pun daftar dikirim = jangan sentuh scope yang sudah ada.
        $keys = ['company_ids' => 'company', 'brand_ids' => 'brand', 'outlet_ids' => 'outlet'];
        if (! array_intersect_key($data, $keys)) {
            return;
        }

        $user->dataScopes()->delete();

        // Role tanpa batas outlet (admin, data.all) tak butuh scope, dan akun
        // karyawan tak melihat data outlet mana pun. Menyimpan barisnya untuk
        // mereka cuma menyesatkan.
        if ($user->hasFullDataAccess() || $user->isEmployee()) {
            return;
        }

        foreach ($keys as $key => $type) {
            foreach (array_unique($data[$key] ?? []) as $scopeId) {
                UserScope::create([
                    'user_id' => $user->id,
                    'scope_type' => $type,
                    'scope_id' => $scopeId,
                ]);
            }
        }
    }

    private static function present(User $user): array
    {
        $byType = $user->dataScopes->groupBy('scope_type');

        return [
            ...$user->only(['id', 'name', 'email', 'role', 'is_active', 'employee_id', 'must_change_password', 'created_at']),
            'last_login_at' => $user->last_login_at?->format('Y-m-d H:i:s'),
            'last_login_ip' => $user->last_login_ip,
            'company_ids' => $byType->get('company')?->pluck('scope_id')->all() ?? [],
            'brand_ids' => $byType->get('brand')?->pluck('scope_id')->all() ?? [],
            'outlet_ids' => $byType->get('outlet')?->pluck('scope_id')->all() ?? [],
        ];
    }
}
