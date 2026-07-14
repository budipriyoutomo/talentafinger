<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu baris = satu penugasan user ke Company / Brand / Outlet.
 * Lihat migrasi create_user_scopes_table untuk aturan pewarisan ke bawah.
 */
class UserScope extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    /** Level penugasan yang dikenal, dari paling luas ke paling sempit. */
    public const TYPES = ['company', 'brand', 'outlet'];

    protected $fillable = [
        'user_id',
        'scope_type',
        'scope_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
