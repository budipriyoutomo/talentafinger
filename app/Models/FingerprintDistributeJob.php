<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FingerprintDistributeJob extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'employee_ids',
        'target_machine_ids',
        'status',
        'progress_total',
        'progress_done',
        'summary',
        'items',
        'error',
    ];

    protected $casts = [
        'employee_ids' => 'array',
        'target_machine_ids' => 'array',
        'summary' => 'array',
        'items' => 'array',
        'progress_total' => 'integer',
        'progress_done' => 'integer',
    ];
}
