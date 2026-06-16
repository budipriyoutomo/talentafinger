<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Machine;

class IdempotencyService
{
    public function isDuplicate(string $machineId, string $bioId, string $timestamp): bool
    {
        return AttendanceLog::where('machine_id', $machineId)
            ->where('biometric_id_lokal', $bioId)
            ->where('timestamp', $timestamp)
            ->exists();
    }

    public function createLog(array $data): AttendanceLog
    {
        return AttendanceLog::create($data);
    }

    public function markDuplicate(string $machineId, string $bioId, string $timestamp): void
    {
        AttendanceLog::where('machine_id', $machineId)
            ->where('biometric_id_lokal', $bioId)
            ->where('timestamp', $timestamp)
            ->where('status_sync', 'pending')
            ->update(['status_sync' => 'duplicate']);
    }
}
