<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'talenta_employee_id',
        'employee_code',
        'biometric_id',
        'outlet_id',
        'device_privilege',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'device_privilege' => 'integer',
    ];

    public function mappings()
    {
        return $this->hasMany(EmployeeMapping::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function biometricTemplates()
    {
        return $this->hasMany(BiometricTemplate::class);
    }
}
