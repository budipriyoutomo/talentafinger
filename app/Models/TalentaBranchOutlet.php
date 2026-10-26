<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mapping cabang Talenta -> outlet lokal. Dipakai sync untuk mengisi outlet
 * HANYA pada karyawan yang belum punya outlet sama sekali (D11).
 */
class TalentaBranchOutlet extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'talenta_branch_id',
        'talenta_branch_name',
        'outlet_id',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }
}
