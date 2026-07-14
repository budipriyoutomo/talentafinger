<?php

namespace App\Policies;

use App\Models\AttendanceLog;
use App\Models\User;

/**
 * Log mengikuti outlet MESIN tempat ia terekam (lihat AttendanceLog::scopeVisibleTo).
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
            && $user->canAccessOutlet($log->machine?->outlet_id);
    }

    /** Kirim / kirim ulang satu log ke Talenta. */
    public function send(User $user, AttendanceLog $log): bool
    {
        return $user->hasPermission('attendance.send')
            && $user->canAccessOutlet($log->machine?->outlet_id);
    }
}
