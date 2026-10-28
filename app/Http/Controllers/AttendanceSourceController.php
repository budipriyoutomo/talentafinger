<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\AttendanceSource;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * Master sumber absensi (tab "Sumber Absensi" di /settings). Admin mengatur
 * nama/deskripsi dan saklar sumber mana yang log-nya dikirim ke Talenta.
 *
 * Tak ada tambah/hapus: kode sumber dihasilkan oleh kode aplikasi.
 */
class AttendanceSourceController extends Controller
{
    /** Daftar untuk tab Settings, lengkap dengan jumlah log 'skipped' per sumber. */
    public static function list(): array
    {
        $skipped = AttendanceLog::where('status_sync', 'skipped')
            ->selectRaw('source, count(*) as total')
            ->groupBy('source')
            ->pluck('total', 'source');

        return AttendanceSource::orderBy('id')->get()
            ->map(fn (AttendanceSource $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'description' => $s->description,
                'sync_to_talenta' => $s->sync_to_talenta,
                'skipped_count' => (int) ($skipped[$s->code] ?? 0),
            ])
            ->all();
    }

    /**
     * PUT /api/attendance-sources/{id}.
     *
     * requeue_skipped=true (hanya bila sync dinyalakan) mengembalikan log lama
     * sumber ini yang berstatus 'skipped' ke 'pending', supaya ikut terkirim
     * pada auto-send / "Kirim Semua Pending" berikutnya.
     */
    public function update(Request $request, int $id)
    {
        $this->authorizePermission($request, 'setting.manage');

        $source = AttendanceSource::findOrFail($id);

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'sync_to_talenta' => 'required|boolean',
            'requeue_skipped' => 'sometimes|boolean',
        ]);

        $source->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'sync_to_talenta' => $data['sync_to_talenta'],
        ]);

        $changes = collect($source->getDirty())
            ->map(fn ($to, $key) => ['from' => $source->getOriginal($key), 'to' => $to])
            ->all();

        $source->save();

        $requeued = 0;
        if ($source->sync_to_talenta && $request->boolean('requeue_skipped')) {
            $requeued = AttendanceLog::where('source', $source->code)
                ->where('status_sync', 'skipped')
                ->update(['status_sync' => 'pending', 'error_message' => null]);
            $changes['requeued_skipped'] = $requeued;
        }

        if ($changes) {
            AuditLog::record('attendance_source.updated', ['type' => 'attendance_source', 'id' => $source->id, 'label' => $source->code], $changes);
        }

        return response()->json([
            'success' => true,
            'message' => $requeued
                ? "Sumber \"{$source->name}\" disimpan. {$requeued} log lama masuk antrean kirim."
                : "Sumber \"{$source->name}\" disimpan.",
        ]);
    }
}
