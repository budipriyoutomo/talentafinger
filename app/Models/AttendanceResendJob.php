<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Satu proses "kirim ulang log gagal" yang berjalan di background.
 * Progresnya dipolling halaman Log Absensi (tab Gagal).
 */
class AttendanceResendJob extends Model
{
    use \Illuminate\Database\Eloquent\Concerns\HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'filters',
        'selected_ids',
        'status',
        'progress_total',
        'progress_done',
        'summary',
        'error',
    ];

    protected $casts = [
        'filters' => 'array',
        'selected_ids' => 'array',
        'summary' => 'array',
        'progress_total' => 'integer',
        'progress_done' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Job masih berjalan = UI harus terus polling & tombol tetap terkunci. */
    public function isRunning(): bool
    {
        return in_array($this->status, ['queued', 'processing'], true);
    }
}
