<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'machine_id',
        'biometric_id_lokal',
        'timestamp',
        'status_sync',
        'payload_raw',
        'error_message',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }
}
