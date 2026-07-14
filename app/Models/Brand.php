<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'company_id',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Outlet di bawahnya ikut terhapus via FK cascade; bersihkan penugasannya. */
    protected static function booted(): void
    {
        static::deleting(function (Brand $brand) {
            $outletIds = Outlet::where('brand_id', $brand->id)->pluck('id');

            UserScope::query()
                ->where(function ($q) use ($brand, $outletIds) {
                    $q->where(fn ($w) => $w->where('scope_type', 'brand')->where('scope_id', $brand->id))
                        ->orWhere(fn ($w) => $w->where('scope_type', 'outlet')->whereIn('scope_id', $outletIds));
                })
                ->delete();
        });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function outlets()
    {
        return $this->hasMany(Outlet::class);
    }

    /**
     * Brand terlihat bila punya minimal satu outlet dalam scope user. Brand yang
     * belum punya outlet sama sekali hanya terlihat oleh user tanpa batas.
     * $user null = konteks sistem, tanpa batas.
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
