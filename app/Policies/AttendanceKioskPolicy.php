<?php

namespace App\Policies;

use App\Models\AttendanceKiosk;
use App\Models\User;

/**
 * Kiosk QR mengikuti outlet tempat tabletnya dipasang: permission kiosk.manage
 * DAN outlet kiosk ada di scope user. Admin dilewatkan oleh Gate::before.
 */
class AttendanceKioskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('kiosk.manage');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('kiosk.manage');
    }

    public function update(User $user, AttendanceKiosk $kiosk): bool
    {
        return $user->hasPermission('kiosk.manage')
            && $user->canAccessOutlet($kiosk->outlet_id);
    }

    public function delete(User $user, AttendanceKiosk $kiosk): bool
    {
        return $this->update($user, $kiosk);
    }

    /** Cabut tablet + rotasi secret + kode aktivasi baru. */
    public function reset(User $user, AttendanceKiosk $kiosk): bool
    {
        return $this->update($user, $kiosk);
    }
}
