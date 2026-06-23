<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceCommand extends Model
{
    protected $fillable = [
        'machine_id',
        'type',
        'command',
        'status',
        'response',
        'sent_at',
        'done_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'done_at' => 'datetime',
    ];

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }
}
