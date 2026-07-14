<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

/**
 * Karyawan bisa berada di BANYAK outlet. Ia tersentuh bila ada IRISAN antara
 * outlet-outletnya dan scope user — jadi karyawan lintas-outlet bisa diurus
 * oleh manajer mana pun yang membawahi salah satu outlet tersebut.
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('employee.view');
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->hasPermission('employee.view')
            && $this->inScope($user, $employee);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('employee.manage');
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->hasPermission('employee.manage')
            && $this->inScope($user, $employee);
    }

    /**
     * Menghapus karyawan membuangnya dari SEMUA outlet — termasuk outlet yang
     * bukan wewenang user ini. Jadi tak cukup beririsan: seluruh outlet karyawan
     * harus ada dalam scope-nya. Untuk melepas karyawan dari satu outlet saja,
     * pakai update (ubah daftar outlet), bukan hapus.
     */
    public function delete(User $user, Employee $employee): bool
    {
        if (! $user->hasPermission('employee.manage')) {
            return false;
        }

        $allowed = $user->scopedOutletIds();

        if ($allowed === null) {
            return true;
        }

        $outletIds = $employee->outlets()->pluck('outlets.id')->all();

        return $outletIds !== [] && array_diff($outletIds, $allowed) === [];
    }

    /** Tarik/sebar sidik jari karyawan ini. */
    public function syncFingerprint(User $user, Employee $employee): bool
    {
        return $user->hasPermission('fingerprint.sync')
            && $this->inScope($user, $employee);
    }

    /**
     * Karyawan TANPA outlet sama sekali hanya bisa disentuh user tanpa batas —
     * kalau tidak, karyawan yatim jadi celah yang terlihat semua orang.
     */
    private function inScope(User $user, Employee $employee): bool
    {
        $allowed = $user->scopedOutletIds();

        if ($allowed === null) {
            return true;
        }

        return $employee->outlets()
            ->whereIn('outlets.id', $allowed)
            ->exists();
    }
}
