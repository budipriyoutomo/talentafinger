<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Perangkat (browser) yang pernah dipakai karyawan untuk absen foto / QR.
 * Lihat AttendanceDeviceService. device_hash = SHA-256 nilai cookie, bukan
 * nilai cookie-nya sendiri.
 */
class EmployeeDevice extends Model
{
    use HasUuids;

    protected $fillable = [
        'employee_id',
        'device_hash',
        'user_agent',
        'first_seen_at',
        'last_seen_at',
        'revoked_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** Hash tak perlu ikut ke browser. */
    protected $hidden = ['device_hash'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at');
    }
}
