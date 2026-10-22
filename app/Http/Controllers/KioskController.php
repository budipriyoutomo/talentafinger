<?php

namespace App\Http\Controllers;

use App\Models\AttendanceKiosk;
use App\Models\AuditLog;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Kelola kiosk QR absensi (tablet di outlet) dari admin panel.
 *
 * Pola otorisasi sama seperti MachineController: daftar disaring visibleTo(),
 * aksi satuan lewat AttendanceKioskPolicy (permission + scope outlet).
 * Endpoint yang dipanggil TABLET ada di KioskDeviceController.
 */
class KioskController extends Controller
{
    /** GET /kiosks — halaman admin. */
    public function page(Request $request)
    {
        $this->authorize('viewAny', AttendanceKiosk::class);

        return Inertia::render('Kiosks', [
            'kiosks' => $this->list($request),
            'outlets' => Outlet::visibleTo($request->user())
                ->with('brand')
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Outlet $o) => [
                    'id' => $o->id,
                    'name' => $o->name,
                    'brand_name' => $o->brand?->name,
                    'has_coordinates' => $o->hasCoordinates(),
                ]),
        ]);
    }

    /** GET /api/kiosks */
    public function index(Request $request)
    {
        $this->authorize('viewAny', AttendanceKiosk::class);

        return $this->list($request);
    }

    /** POST /api/kiosks — buat kiosk + kode aktivasi (ditampilkan sekali). */
    public function store(Request $request)
    {
        $this->authorize('create', AttendanceKiosk::class);

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'outlet_id' => 'required|exists:outlets,id',
        ]);

        $this->assertOutletInScope($request, $data['outlet_id']);

        [$kiosk, $code] = DB::transaction(function () use ($data) {
            $kiosk = new AttendanceKiosk($data);
            $code = $kiosk->issueActivationCode();
            $kiosk->save();

            return [$kiosk, $code];
        });

        AuditLog::record('kiosk.created', $kiosk, $data);

        return response()->json($this->activationResponse($kiosk, $code), 201);
    }

    /** PUT /api/kiosks/{id} — nama & aktif. Outlet tak bisa dipindah (buat kiosk baru saja). */
    public function update(Request $request, string $id)
    {
        $kiosk = AttendanceKiosk::findOrFail($id);
        $this->authorize('update', $kiosk);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'is_active' => 'sometimes|boolean',
        ]);

        $before = $kiosk->only(array_keys($data));
        $kiosk->update($data);

        $changes = AuditLog::diff($before, $kiosk->only(array_keys($data)));
        if ($changes) {
            AuditLog::record('kiosk.updated', $kiosk, $changes);
        }

        return $this->present($kiosk->load('outlet'));
    }

    /** POST /api/kiosks/{id}/reset — cabut tablet lama, rotasi secret, kode baru. */
    public function reset(string $id)
    {
        $kiosk = AttendanceKiosk::findOrFail($id);
        $this->authorize('reset', $kiosk);

        $code = $kiosk->resetActivation();
        AuditLog::record('kiosk.reset', $kiosk);

        return response()->json($this->activationResponse($kiosk, $code));
    }

    /** DELETE /api/kiosks/{id} — log absen QR lama tetap ada (kiosk_id jadi null). */
    public function destroy(string $id)
    {
        $kiosk = AttendanceKiosk::findOrFail($id);
        $this->authorize('delete', $kiosk);

        AuditLog::record('kiosk.deleted', $kiosk, $kiosk->only(['name', 'outlet_id']));
        $kiosk->delete();

        return response()->json(['success' => true]);
    }

    private function list(Request $request): array
    {
        return AttendanceKiosk::visibleTo($request->user())
            ->with('outlet')
            ->orderBy('name')
            ->get()
            ->map(fn (AttendanceKiosk $k) => $this->present($k))
            ->all();
    }

    private function present(AttendanceKiosk $kiosk): array
    {
        return [
            'id' => $kiosk->id,
            'name' => $kiosk->name,
            'outlet_id' => $kiosk->outlet_id,
            'outlet_name' => $kiosk->outlet?->name,
            'outlet_has_coordinates' => (bool) $kiosk->outlet?->hasCoordinates(),
            'is_active' => $kiosk->is_active,
            'is_activated' => $kiosk->isActivated(),
            'is_online' => $kiosk->isOnline(),
            'activated_at' => $kiosk->activated_at?->toIso8601String(),
            'last_seen_at' => $kiosk->last_seen_at?->toIso8601String(),
            'activation_pending' => $kiosk->activation_expires_at?->isFuture() ?? false,
            'activation_expires_at' => $kiosk->activation_expires_at?->toIso8601String(),
        ];
    }

    private function activationResponse(AttendanceKiosk $kiosk, string $code): array
    {
        return [
            'kiosk' => $this->present($kiosk->load('outlet')),
            'activation_code' => $code,
            'activation_expires_at' => $kiosk->activation_expires_at->toIso8601String(),
        ];
    }
}
