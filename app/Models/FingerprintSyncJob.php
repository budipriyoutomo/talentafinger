<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FingerprintSyncJob extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'source_machine_id',
        'target_machine_ids',
        'pins',
        'status',
        'progress_total',
        'progress_done',
        'summary',
        'items',
        'error',
    ];

    protected $casts = [
        'target_machine_ids' => 'array',
        'pins' => 'array',
        'summary' => 'array',
        'items' => 'array',
        'progress_total' => 'integer',
        'progress_done' => 'integer',
    ];

    public function source()
    {
        return $this->belongsTo(Machine::class, 'source_machine_id');
    }
}
