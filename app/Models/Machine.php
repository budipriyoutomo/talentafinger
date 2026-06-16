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
        'last_seen_at',
        'status',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function employeeMappings()
    {
        return $this->hasMany(EmployeeMapping::class);
    }

    public function attendanceLogs()
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at && $this->last_seen_at->isAfter(now()->subMinutes(5));
    }
}
