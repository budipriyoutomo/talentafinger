<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeMapping extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'machine_id',
        'employee_id',
        'biometric_id_lokal',
    ];

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
