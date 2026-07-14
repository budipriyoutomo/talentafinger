<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Brand & outlet di bawahnya ikut terhapus lewat FK cascade di DB — yang
     * TIDAK memicu event Eloquent mereka. Jadi penugasan user ke seluruh subtree
     * ini harus dibersihkan di sini, kalau tidak tabel user_scopes meninggalkan
     * baris yatim yang menunjuk ke organisasi yang sudah tiada.
     */
    protected static function booted(): void
    {
        static::deleting(function (Company $company) {
            $brandIds = Brand::where('company_id', $company->id)->pluck('id');
            $outletIds = Outlet::whereIn('brand_id', $brandIds)->pluck('id');

            UserScope::query()
                ->where(function ($q) use ($company, $brandIds, $outletIds) {
                    $q->where(fn ($w) => $w->where('scope_type', 'company')->where('scope_id', $company->id))
                        ->orWhere(fn ($w) => $w->where('scope_type', 'brand')->whereIn('scope_id', $brandIds))
                        ->orWhere(fn ($w) => $w->where('scope_type', 'outlet')->whereIn('scope_id', $outletIds));
                })
                ->delete();
        });
    }

    public function brands()
    {
        return $this->hasMany(Brand::class);
    }

    /**
     * Company terlihat bila punya minimal satu outlet dalam scope user.
     * $user null = konteks sistem, tanpa batas.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        $outletIds = $user?->scopedOutletIds();

        if ($outletIds === null) {
            return $query;
        }

        return $query->whereHas(
            'brands.outlets',
            fn ($q) => $q->whereIn('outlets.id', $outletIds),
        );
    }
}
