<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Services\DeviceCommandService;
use App\Services\ZkSyncService;
use Illuminate\Http\Request;

/**
 * Endpoint mesin fingerprint untuk dashboard.
 *
 * Pola otorisasi di seluruh controller ini:
 *   - DAFTAR  -> Machine::visibleTo($user), jadi mesin di luar scope tak muncul.
 *   - SATUAN  -> $this->authorize(...) pada objeknya, jadi menebak ID mesin
 *                milik outlet lain tetap ditolak 403 (bukan cuma disembunyikan).
 * Endpoint iClock yang dipanggil MESIN ada di AdmsController dan tetap publik.
 */
class MachineController extends Controller
{
    public function __construct(private ZkSyncService $zk, private DeviceCommandService $commands)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Machine::class);

        // Kolom `status` di DB hanya di-set 'online' saat mesin push data dan tidak
        // pernah direset, jadi nilainya basi. Hitung status LIVE dari last_seen_at
        // (window 5 menit via isOnline()) supaya heartbeat monitor akurat.
        return Machine::visibleTo($request->user())
            ->get()
            ->each(function ($m) {
                $m->status = $m->isOnline() ? 'online' : 'offline';
                // Status jalur TCP 4370 (server -> mesin), terpisah dari status ADMS.
                $m->tcp_ready = $m->tcpReady();
            });
    }

    public function store(Request $request)
    {
        $this->authorize('create', Machine::class);

        $data = $request->validate([
            'serial_number' => 'required|unique:machines',
            'name' => 'required',
            // Outlet tempat mesin terpasang; jadi acuan pembatasan akses per-outlet.
            'outlet_id' => 'nullable|exists:outlets,id',
            'location' => 'nullable',
            'ip_address' => 'nullable|ip',
            'sdk_port' => 'nullable|integer|min:1|max:65535',
            'is_active' => 'boolean',
        ]);

        $this->assertOutletInScope($request, $data['outlet_id'] ?? null);

        return Machine::create($data);
    }

    public function update(Request $request, string $id)
    {
        $machine = Machine::findOrFail($id);
        $this->authorize('update', $machine);

        $data = $request->validate([
            'serial_number' => 'required|unique:machines,serial_number,' . $id,
            'name' => 'required',
            'outlet_id' => 'nullable|exists:outlets,id',
            'location' => 'nullable',
            'ip_address' => 'nullable|ip',
            'sdk_port' => 'nullable|integer|min:1|max:65535',
            'is_active' => 'boolean',
        ]);

        // Memindahkan mesin KE LUAR scope sendiri = membuangnya dari jangkauan
        // (dan menyerahkannya ke orang lain), jadi outlet tujuan juga harus sah.
        if (array_key_exists('outlet_id', $data)) {
            $this->assertOutletInScope($request, $data['outlet_id']);
        }

        $machine->update($data);

        return $machine;
    }

    public function destroy(string $id)
    {
        $machine = Machine::findOrFail($id);
        $this->authorize('delete', $machine);

        $machine->delete();

        return response()->json(['success' => true]);
    }

    /** Antrekan perintah sync time (dieksekusi saat mesin polling getrequest). */
    public function syncTime(string $id)
    {
        $machine = Machine::findOrFail($id);
        $this->authorize('operate', $machine);

        $cmd = $this->commands->queueSyncTime($machine);

        return response()->json([
            'success' => true,
            'message' => 'Perintah sync time diantrekan. Mesin akan menyesuaikan jam saat polling berikutnya (±30 detik).',
            'command' => $cmd->command,
        ]);
    }

    /**
     * Probe MANUAL jalur TCP 4370 (server -> mesin) untuk satu mesin, sekarang juga.
     * Sama seperti command terjadwal machine:probe-tcp tapi dipicu dari tombol UI;
     * menyimpan hasil ke kolom tcp_* supaya badge status langsung ikut ter-update.
     */
    public function probeTcp(Request $request, string $id)
    {
        $machine = Machine::findOrFail($id);
        // Sekadar diagnostik, tak mengubah perangkat -> cukup hak lihat.
        $this->authorize('view', $machine);

        if (! $machine->ip_address) {
            return response()->json([
                'ok' => false,
                'error' => "Mesin {$machine->name} belum punya IP LAN. Isi IP dulu untuk menguji jalur TCP 4370.",
            ], 422);
        }

        $res = $this->zk->ping($machine);
        $online = (bool) ($res['ok'] ?? false);

        $machine->update([
            'tcp_checked_at' => now(),
            'tcp_online' => $online,
            'tcp_latency_ms' => $online ? ($res['latency_ms'] ?? null) : null,
            'tcp_error' => $online ? null : ($res['error'] ?? 'Tidak terjangkau'),
        ]);

        return response()->json([
            'ok' => $online,
            'latency_ms' => $online ? ($res['latency_ms'] ?? null) : null,
            'error' => $online ? null : ($res['error'] ?? 'Tidak terjangkau'),
        ], $online ? 200 : 422);
    }

    /** Diagnostik: kapasitas & info perangkat (via 4370). */
    public function zkInfo(string $id)
    {
        $machine = Machine::findOrFail($id);
        $this->authorize('view', $machine);

        return response()->json($this->zk->info($machine));
    }

    /** Daftar user terdaftar di mesin, dibaca LIVE (via 4370). */
    public function zkUsers(string $id)
    {
        $machine = Machine::findOrFail($id);
        $this->authorize('view', $machine);

        return response()->json($this->zk->listUsers($machine));
    }

    /**
     * Hapus 1 user (beserta sidik jarinya) dari mesin via 4370. Permanen di perangkat;
     * tak menyentuh mapping/log di DB. Hapus per jari tidak didukung protokol ZK.
     */
    public function deleteZkUser(Request $request, string $id, string $pin)
    {
        $machine = Machine::findOrFail($id);
        // Menghapus user dari perangkat = ranah sidik jari, bukan kelola mesin.
        $this->authorize('view', $machine);
        $this->authorizePermission($request, 'fingerprint.delete');

        if (! $machine->ip_address) {
            return response()->json(['ok' => false, 'error' => "Mesin {$machine->name} belum punya IP."], 422);
        }

        $result = $this->zk->delete($machine, $pin);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    /**
     * Hapus SEMUA log presensi (records) di mesin via 4370. Permanen di perangkat;
     * tidak menyentuh log di DB / Talenta. ZK tak mendukung hapus parsial by tanggal.
     */
    public function clearAttendance(string $id)
    {
        $machine = Machine::findOrFail($id);
        $this->authorize('operate', $machine);

        if (! $machine->ip_address) {
            return response()->json(['ok' => false, 'error' => "Mesin {$machine->name} belum punya IP."], 422);
        }

        $result = $this->zk->clearAttendance($machine);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }
}
