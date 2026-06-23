<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FingerprintDeleteJob extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'machine_id',
        'pins',
        'status',
        'progress_total',
        'progress_done',
        'summary',
        'items',
        'error',
    ];

    protected $casts = [
        'pins' => 'array',
        'summary' => 'array',
        'items' => 'array',
        'progress_total' => 'integer',
        'progress_done' => 'integer',
    ];

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }
}
