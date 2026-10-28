<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;

/**
 * Aturan PENDELEGASIAN hak akses: apa yang boleh diberikan seorang pengelola
 * (pemegang user.manage / role.manage) kepada orang lain.
 *
 * Prinsipnya satu: tak seorang pun bisa memberi lebih dari yang ia punya —
 * baik permission (sumbu 1) maupun scope outlet (sumbu 2). Admin bebas.
 * Tanpa aturan ini, pengelola non-admin bisa menaikkan dirinya sendiri lewat
 * user lain atau lewat role yang ia sunting.
 */
class AccessDelegation
{
    public function __construct(private User $actor) {}

    public static function for(User $actor): self
    {
        return new self($actor);
    }

    /** Permission yang boleh dicentang aktor pada role. */
    public function grantablePermissions(): array
    {
        $assignable = Role::assignablePermissions();

        if ($this->actor->isAdmin()) {
            return $assignable;
        }

        return array_values(array_filter($assignable, fn ($p) => $this->actor->hasPermission($p)));
    }

    /** Semua permission ini boleh diberikan aktor? (khusus-sistem diabaikan) */
    public function canGrant(array $permissions): bool
    {
        if ($this->actor->isAdmin()) {
            return true;
        }

        $relevant = array_diff($permissions, config('permissions.system_only', []));

        // '*' hanya milik role admin — tak pernah bisa didelegasikan non-admin.
        return ! in_array('*', $relevant, true)
            && array_diff($relevant, $this->grantablePermissions()) === [];
    }

    /** Aktor boleh memberikan role ini ke user lain? */
    public function canAssignRole(string $roleKey): bool
    {
        if ($roleKey === Role::ADMIN) {
            return $this->actor->isAdmin();
        }

        return $this->canGrant(Role::permissionsFor($roleKey));
    }

    /** Aktor boleh menyunting permission role ini? */
    public function canEditRole(Role $role): bool
    {
        if ($this->actor->isAdmin()) {
            return true;
        }

        // Menyunting role sendiri = menaikkan diri sendiri.
        return $role->key !== $this->actor->role && $this->canGrant($role->permissions());
    }

    /**
     * Aktor boleh mengubah / menghapus user ini? Hanya bila seluruh hak user
     * itu (role + scope) masih di dalam hak aktor.
     */
    public function canManageUser(User $target): bool
    {
        if ($this->actor->isAdmin()) {
            return true;
        }

        if ($target->isAdmin() || ! $this->canAssignRole($target->role)) {
            return false;
        }

        if ($target->isEmployee()) {
            return $this->canLinkEmployee($target->employee_id);
        }

        // User role lain yang juga karyawan: karyawannya pun harus dalam jangkauan.
        if ($target->isLinkedEmployee() && ! $this->canLinkEmployee($target->employee_id)) {
            return false;
        }

        $allowed = $this->actor->scopedOutletIds();

        return $allowed === null
            || array_diff($target->scopedOutletIds() ?? [], $allowed) === [];
    }

    /**
     * Scope yang ditugaskan harus berasal dari scope aktor sendiri, PER LEVEL:
     * company hanya bila aktor ditugaskan company itu, brand bila aktor punya
     * brand itu / company induknya, outlet bila outlet itu dalam jangkauannya.
     * (Mengecek hanya outlet hari ini tak cukup: company yang kelak bertambah
     * outlet akan ikut terbawa ke user lain, padahal aktor tak memilikinya.)
     */
    public function canAssignScopes(array $companyIds, array $brandIds, array $outletIds): bool
    {
        if ($this->actor->hasFullDataAccess()) {
            return true;
        }

        $own = $this->actor->directScopeIds();
        $allowedBrands = array_merge(
            $own['brand'],
            $own['company'] ? Brand::whereIn('company_id', $own['company'])->pluck('id')->all() : [],
        );

        return array_diff($companyIds, $own['company']) === []
            && array_diff($brandIds, $allowedBrands) === []
            && array_diff($outletIds, $this->actor->scopedOutletIds() ?? []) === [];
    }

    /** Karyawan ini boleh ditautkan aktor ke sebuah akun? */
    public function canLinkEmployee(?string $employeeId): bool
    {
        if ($employeeId === null || $this->actor->hasFullDataAccess()) {
            return true;
        }

        return Employee::visibleTo($this->actor)->whereKey($employeeId)->exists();
    }
}
