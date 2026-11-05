<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeDevice;

/**
 * Perangkat absen foto / QR milik karyawan (deteksi titip absen, R4).
 * Reset dipakai saat karyawan ganti HP: perangkat lama tak lagi dihitung
 * aktif, jadi HP baru tidak ditandai "perangkat baru".
 */
class EmployeeDeviceController extends Controller
{
    /** GET /api/employees/{id}/devices */
    public function index(string $id)
    {
        $employee = Employee::findOrFail($id);
        $this->authorize('view', $employee);

        return response()->json([
            'devices' => $employee->devices()
                ->orderByRaw('(revoked_at IS NULL) DESC')
                ->orderByDesc('last_seen_at')
                ->get()
                ->map(fn (EmployeeDevice $d) => [
                    'id' => $d->id,
                    'user_agent' => $d->user_agent,
                    'first_seen_at' => $d->first_seen_at?->format('Y-m-d H:i:s'),
                    'last_seen_at' => $d->last_seen_at?->format('Y-m-d H:i:s'),
                    'revoked_at' => $d->revoked_at?->format('Y-m-d H:i:s'),
                ]),
        ]);
    }

    /** DELETE /api/employees/{id}/devices/{deviceId} — reset (nonaktifkan) satu perangkat. */
    public function destroy(string $id, string $deviceId)
    {
        $employee = Employee::findOrFail($id);
        $this->authorize('update', $employee);

        $device = $employee->devices()->whereKey($deviceId)->firstOrFail();

        if ($device->revoked_at === null) {
            $device->update(['revoked_at' => now()]);

            AuditLog::record('employee.device_reset', $employee, [
                'device_id' => $device->id,
                'user_agent' => $device->user_agent,
            ]);
        }

        return response()->json(['message' => 'Perangkat direset. Absen berikutnya dari HP baru tidak akan ditandai.']);
    }
}
