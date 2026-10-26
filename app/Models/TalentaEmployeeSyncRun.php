<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Riwayat 1 run sync karyawan Talenta: `pull` (Tahap 1) atau `sync` (Tahap 2). */
class TalentaEmployeeSyncRun extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const TYPE_PULL = 'pull';
    public const TYPE_SYNC = 'sync';

    protected $fillable = [
        'type',
        'triggered_by',
        'status',
        'total_fetched',
        'count_changed',
        'count_created',
        'count_updated',
        'count_deactivated',
        'count_conflict',
        'count_error',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'total_fetched' => 'integer',
        'count_changed' => 'integer',
        'count_created' => 'integer',
        'count_updated' => 'integer',
        'count_deactivated' => 'integer',
        'count_conflict' => 'integer',
        'count_error' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function triggeredBy()
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function isRunning(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }
}
