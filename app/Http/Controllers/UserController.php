<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

/**
 * Manajemen user, role, dan SCOPE (Company/Brand/Outlet yang jadi wewenangnya).
 * Admin-only — ini pintu yang bisa dipakai memperluas hak akses diri sendiri.
 */
class UserController extends Controller
{
    public function index()
    {
        return User::with('dataScopes')->orderBy('name')->get()
            ->map(fn (User $u) => $this->present($u));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8',
            'role' => ['required', Rule::in(User::ROLES)],
            ...$this->scopeRules(),
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
        ]);

        $this->syncScopes($user, $data);

        return response()->json($this->present($user->fresh('dataScopes')), 201);
    }

    public function update(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:150',
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($id)],
            // Password opsional saat edit: kosong = jangan ubah.
            'password' => 'nullable|string|min:8',
            'role' => ['required', Rule::in(User::ROLES)],
            ...$this->scopeRules(),
        ]);

        // Jangan biarkan admin terakhir menurunkan dirinya sendiri / user admin
        // terakhir, supaya aplikasi tak terkunci tanpa admin.
        if ($user->isAdmin() && $data['role'] !== 'admin'
            && User::where('role', 'admin')->count() <= 1) {
            return response()->json(['message' => 'Tidak bisa menurunkan admin terakhir. Buat admin lain dulu.'], 422);
        }

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->role = $data['role'];
        if (filled($data['password'] ?? null)) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        $this->syncScopes($user, $data);

        return response()->json($this->present($user->fresh('dataScopes')));
    }

    public function destroy(Request $request, string $id)
    {
        $user = User::findOrFail($id);

        if ((int) $id === (int) $request->user()->id) {
            return response()->json(['message' => 'Tidak bisa menghapus akun yang sedang login.'], 422);
        }

        if ($user->isAdmin() && User::where('role', 'admin')->count() <= 1) {
            return response()->json(['message' => 'Tidak bisa menghapus admin terakhir.'], 422);
        }

        // user_scopes ikut terhapus lewat FK cascade.
        $user->delete();

        return response()->json(['success' => true]);
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

        // Admin tak pernah dibatasi outlet; simpan barisnya cuma menyesatkan.
        if ($user->isAdmin()) {
            $user->dataScopes()->delete();

            return;
        }

        $user->dataScopes()->delete();

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

    private function present(User $user): array
    {
        $byType = $user->dataScopes->groupBy('scope_type');

        return [
            ...$user->only(['id', 'name', 'email', 'role', 'created_at']),
            'company_ids' => $byType->get('company')?->pluck('scope_id')->all() ?? [],
            'brand_ids' => $byType->get('brand')?->pluck('scope_id')->all() ?? [],
            'outlet_ids' => $byType->get('outlet')?->pluck('scope_id')->all() ?? [],
        ];
    }
}
