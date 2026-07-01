<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Machine extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'serial_number',
        'name',
        'location',
        'ip_address',
        'sdk_port',
        'last_seen_at',
        'status',
        'is_active',
        'tcp_checked_at',
        'tcp_online',
        'tcp_latency_ms',
        'tcp_error',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'is_active' => 'boolean',
        'sdk_port' => 'integer',
        'tcp_checked_at' => 'datetime',
        'tcp_online' => 'boolean',
        'tcp_latency_ms' => 'integer',
    ];

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->isAfter(now()->subMinutes(5));
    }

    /**
     * Jalur TCP 4370 (server -> mesin) siap? Bernilai true hanya bila probe
     * TERAKHIR sukses DAN masih segar (<= 15 menit) supaya hasil basi tidak
     * terus tampil hijau. null = belum pernah diprobe.
     */
    public function tcpReady(): ?bool
    {
        if (! $this->tcp_checked_at) {
            return null;
        }
        if ($this->tcp_checked_at->isBefore(now()->subMinutes(15))) {
            return null; // hasil kadaluarsa, anggap belum diketahui
        }
        return (bool) $this->tcp_online;
    }
}
