<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Snapshot 1 karyawan dari Talenta (Tahap 1 sync). Sumber Tahap 2 yang
 * membuat/memperbarui/menonaktifkan baris `employees`.
 */
class TalentaEmployee extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const STATUS_ACTIVE = 'Active';
    public const STATUS_RESIGNED = 'Resigned';

    public const SYNC_STATES = ['pending', 'synced', 'conflict', 'ignored', 'error'];
    public const MATCH_STATUSES = ['linked', 'new', 'conflict', 'resigned'];

    protected $fillable = [
        'talenta_user_id',
        'barcode',
        'employee_code',
        'full_name',
        'email',
        'job_position',
        'organization_name',
        'branch_id',
        'branch_name',
        'status',
        'resign_date',
        'payload',
        'payload_hash',
        'employee_id',
        'match_status',
        'sync_state',
        'sync_error',
        'synced_at',
        'last_pull_run_id',
        'fetched_at',
        'missing_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'resign_date' => 'date',
        'synced_at' => 'datetime',
        'fetched_at' => 'datetime',
        'missing_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function lastPullRun()
    {
        return $this->belongsTo(TalentaEmployeeSyncRun::class, 'last_pull_run_id');
    }

    public function isResigned(): bool
    {
        return $this->status === self::STATUS_RESIGNED;
    }

    public function scopePending($query)
    {
        return $query->where('sync_state', 'pending');
    }
}
