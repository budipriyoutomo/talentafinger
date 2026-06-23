<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BiometricTemplate extends Model
{
    protected $fillable = [
        'employee_id',
        'biometric_id',
        'fid',
        'size',
        'valid',
        'template',
        'source_machine_id',
        'enrolled_at',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function sourceMachine()
    {
        return $this->belongsTo(Machine::class, 'source_machine_id');
    }
}
