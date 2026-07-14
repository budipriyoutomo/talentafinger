<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'machine_id',
        'biometric_id_lokal',
        'timestamp',
        'status_sync',
        'payload_raw',
        'error_message',
    ];

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * Log mengikuti outlet MESIN tempat ia terekam (bukan outlet karyawan),
     * karena log memang peristiwa fisik di outlet tersebut. Konsekuensinya: log
     * dari mesin yang belum ditempatkan hanya terlihat oleh user tanpa batas.
     * $user null = konteks sistem (queue/command), tanpa batas.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        $outletIds = $user?->scopedOutletIds();

        if ($outletIds === null) {
            return $query;
        }

        return $query->whereHas('machine', fn ($q) => $q->whereIn('outlet_id', $outletIds));
    }
}
