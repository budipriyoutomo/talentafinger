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
        'talenta_user_id',
        'employee_code',
        'biometric_id',
        'device_privilege',
        'is_active',
        'allow_manual_attendance',
        // Salinan data Talenta, ditimpa otomatis oleh sync karyawan.
        'email',
        'job_position',
        'organization_name',
        'branch_name',
        'talenta_status',
        'resign_date',
        'talenta_synced_at',
        'removed_from_machines_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'allow_manual_attendance' => 'boolean',
        'device_privilege' => 'integer',
        'resign_date' => 'date',
        'talenta_synced_at' => 'datetime',
        'removed_from_machines_at' => 'datetime',
    ];

    /** Snapshot Talenta yang tertaut ke karyawan ini (hasil sync Tahap 2). */
    public function talentaEmployee()
    {
        return $this->hasOne(TalentaEmployee::class);
    }

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

    /**
     * Karyawan terlihat bila punya MINIMAL SATU outlet di dalam scope user.
     * Karyawan tanpa outlet sama sekali hanya terlihat oleh user tanpa batas.
     * $user null = konteks sistem (queue/command), tanpa batas.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        $outletIds = $user?->scopedOutletIds();

        if ($outletIds === null) {
            return $query;
        }

        return $query->whereHas('outlets', fn ($q) => $q->whereIn('outlets.id', $outletIds));
    }
}
