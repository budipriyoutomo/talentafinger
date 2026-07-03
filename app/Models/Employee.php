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
        'device_privilege',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'device_privilege' => 'integer',
    ];

    /**
     * Satu karyawan bisa terdaftar di banyak outlet (pivot employee_outlet).
     * Brand & company tersirat dari masing-masing outlet.
     */
    public function outlets()
    {
        return $this->belongsToMany(Outlet::class, 'employee_outlet')->withTimestamps();
    }

    public function biometricTemplates()
    {
        return $this->hasMany(BiometricTemplate::class);
    }
}
