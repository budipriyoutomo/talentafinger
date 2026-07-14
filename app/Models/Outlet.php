<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Outlet extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'brand_id',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Outlet hilang -> penugasan user ke outlet itu ikut dibuang. */
    protected static function booted(): void
    {
        static::deleting(function (Outlet $outlet) {
            UserScope::where('scope_type', 'outlet')->where('scope_id', $outlet->id)->delete();
        });
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    /** Pivot employee_outlet: satu outlet punya banyak karyawan, dan sebaliknya. */
    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'employee_outlet')->withTimestamps();
    }

    public function machines()
    {
        return $this->hasMany(Machine::class);
    }

    /** Outlet yang boleh dilihat user. $user null = konteks sistem, tanpa batas. */
    public function scopeVisibleTo($query, ?User $user)
    {
        $outletIds = $user?->scopedOutletIds();

        if ($outletIds === null) {
            return $query;
        }

        return $query->whereIn('id', $outletIds);
    }
}
