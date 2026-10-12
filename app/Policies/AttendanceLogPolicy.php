<?php

namespace App\Policies;

use App\Models\AttendanceLog;
use App\Models\User;

/**
 * Log mesin mengikuti outlet MESIN tempat ia terekam; log manual mengikuti
 * outlet tempat absen (lihat AttendanceLog::scopeVisibleTo / owningOutletId).
 */
class AttendanceLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('attendance.view');
    }

    public function view(User $user, AttendanceLog $log): bool
    {
        return $user->hasPermission('attendance.view')
            && $user->canAccessOutlet($log->owningOutletId());
    }

    /** Kirim / kirim ulang satu log ke Talenta. */
    public function send(User $user, AttendanceLog $log): bool
    {
        return $user->hasPermission('attendance.send')
            && $user->canAccessOutlet($log->owningOutletId());
    }

    /** Setujui / tolak absen manual pakai foto. */
    public function review(User $user, AttendanceLog $log): bool
    {
        return $log->isManual()
            && $user->hasPermission('attendance.review')
            && $user->canAccessOutlet($log->outlet_id);
    }

    /**
     * Lihat foto absen manual: supervisor yang boleh melihat log-nya, atau
     * karyawan pemilik absen itu sendiri.
     */
    public function viewPhoto(User $user, AttendanceLog $log): bool
    {
        if (! $log->isManual()) {
            return false;
        }

        return ($user->employee_id !== null && $user->employee_id === $log->employee_id)
            || $this->view($user, $log);
    }
}
