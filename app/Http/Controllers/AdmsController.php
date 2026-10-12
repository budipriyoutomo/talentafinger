<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Machine;
use App\Models\DeviceCommand;
use App\Models\BiometricTemplate;
use App\Services\AdmsParserService;
use App\Services\DeviceCommandService;
use App\Services\IdempotencyService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

class AdmsController extends Controller
{
    public function __construct(
        private AdmsParserService $parserService,
        private IdempotencyService $idempotencyService,
        private DeviceCommandService $commandService,
    ) {}

    public function handshake(Request $request)
    {
        $sn = $request->query('SN');

        if (!$sn) {
            return response('Unauthorized', 200);
        }

        $machine = Machine::where('serial_number', $sn)->first();

        if (!$machine) {
            return response('Unauthorized', 200);
        }

        // Status online/offline dihitung LIVE dari last_seen_at (Machine::isOnline());
        // kolom `status` tak lagi ditulis karena tak pernah direset ke offline.
        $machine->update(['last_seen_at' => now()]);

        // Mesin nonaktif: jangan beri config upload, supaya datanya tidak masuk.
        if (!$machine->is_active) {
            return response('Unauthorized', 200);
        }

        // Config iClock: instruksikan mesin untuk push ATTLOG secara realtime.
        // Tanpa blok ini (mis. hanya membalas SN), mesin nyangkut di loop
        // handshake dan tidak pernah meng-upload data absensi.
        $config = implode("\n", [
            "GET OPTION FROM: {$sn}",
            "Stamp=9999",
            "OpStamp=9999",
            "ErrorDelay=30",
            "Delay=30",
            "TransTimes=00:00;14:05",
            "TransInterval=1",
            // TransFlag=1111111111: aktifkan upload SEMUA jenis data
            // (absensi + operlog + user + sidik jari), supaya saat enroll
            // baru, template biometrik ikut di-push ke server.
            "TransFlag=1111111111",
            "TimeZone=7",
            "Realtime=1",
            "Encrypt=0",
        ]) . "\n";

        return response($config, 200)->header('Content-Type', 'text/plain');
    }

    public function ingest(Request $request)
    {
        // SN dikirim mesin di query string (?SN=...&table=ATTLOG&Stamp=...),
        // bukan di body.
        $sn = $request->query('SN');
        $table = $request->query('table');

        if (!$sn) {
            return response('OK', 200);
        }

        $machine = Machine::where('serial_number', $sn)->first();

        if (!$machine) {
            return response('OK', 200);
        }

        $machine->update(['last_seen_at' => now()]);

        // Mesin nonaktif: ack saja, jangan simpan datanya.
        if (!$machine->is_active) {
            return response('OK', 200);
        }

        // Tabel selain ATTLOG (OPERLOG, dll) berisi data biometrik.
        // X100-C mengirim template sidik jari sebagai baris "FP PIN=...".
        if ($table !== null && strtoupper($table) !== 'ATTLOG') {
            // Catatan mentah untuk audit/debug format.
            Log::channel('biometric')->info('Upload biometrik (non-ATTLOG)', [
                'sn'    => $sn,
                'table' => $table,
                'query' => $request->query(),
                'body'  => $request->getContent(),
            ]);

            // Simpan/refresh template sidik jari ke DB (PIN dianggap sama
            // di semua mesin → unik per PIN+jari). updateOrCreate supaya
            // enroll ulang menimpa template lama.
            foreach ($this->parserService->parseFingerprints($request->getContent()) as $fp) {
                BiometricTemplate::updateOrCreate(
                    ['biometric_id' => $fp['biometric_id'], 'fid' => $fp['fid']],
                    [
                        'size'              => $fp['size'],
                        'valid'             => $fp['valid'],
                        'template'          => $fp['template'],
                        'source_machine_id' => $machine->id,
                        'enrolled_at'       => now(),
                    ]
                );
            }

            return response('OK', 200);
        }

        // Satu POST bisa berisi banyak record (satu per baris).
        $records = $this->parserService->parse($request->getContent());

        foreach ($records as $rec) {
            // Mesin mengirim ulang record yang sama bila ack-nya tak sampai
            // (koneksi putus-nyambung). Kiriman ulang cukup DIABAIKAN — log asli
            // tak disentuh, jadi yang masih 'pending' tetap akan terkirim ke
            // Talenta. (Dulu log asli diubah jadi 'duplicate' sehingga absensinya
            // tak pernah terkirim.)
            $isDuplicate = $this->idempotencyService->isDuplicate(
                $machine->id,
                $rec['biometric_id'],
                $rec['timestamp']
            );

            if ($isDuplicate) {
                continue;
            }

            // Simpan sebagai 'pending'. Pengiriman ke Talenta dilakukan oleh
            // auto-send terjadwal atau tombol kirim manual di aplikasi.
            try {
                $this->idempotencyService->createLog([
                    'machine_id' => $machine->id,
                    'biometric_id_lokal' => $rec['biometric_id'],
                    'timestamp' => $rec['timestamp'],
                    'status_sync' => 'pending',
                    'payload_raw' => $rec['raw'],
                ]);
            } catch (UniqueConstraintViolationException) {
                // Kalah balapan dengan POST lain yang menyimpan record sama di
                // antara cek isDuplicate() dan insert ini — unique index DB yang
                // menangkapnya. Abaikan, persis seperti kiriman ulang biasa.
            }
        }

        return response('OK', 200);
    }

    /**
     * Mesin mem-polling endpoint ini untuk mengambil perintah dari server
     * (mis. sync time). Format balasan per baris: "C:<id>:<command>".
     */
    public function getrequest(Request $request)
    {
        $sn = $request->query('SN');
        $machine = Machine::where('serial_number', $sn)->first();

        if (!$machine) {
            return response('OK', 200);
        }

        $machine->update(['last_seen_at' => now()]);

        if (!$machine->is_active) {
            return response('OK', 200);
        }

        // Pending + 'sent' basi (dikirim ulang); sudah ditandai 'sent' di dalamnya.
        $commands = $this->commandService->takeForDelivery($machine);

        if ($commands->isEmpty()) {
            return response('OK', 200);
        }

        $lines = $commands->map(fn (DeviceCommand $cmd) => "C:{$cmd->id}:{$cmd->command}")->all();

        return response(implode("\n", $lines) . "\n", 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Mesin melaporkan hasil eksekusi perintah ke endpoint ini.
     * Body contoh: "ID=12&Return=0&CMD=OPTIONS".
     */
    public function devicecmd(Request $request)
    {
        // Mesin mengirim SN di query string (?SN=...), sama seperti getrequest.
        // Tanpa pengecekan ini, siapa pun yang menebak ID perintah bisa menandai
        // perintah mesin lain 'done' sehingga tak pernah dieksekusi.
        $machine = Machine::where('serial_number', $request->query('SN'))->first();

        if (!$machine) {
            return response('OK', 200);
        }

        foreach (explode("\n", trim($request->getContent())) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            parse_str($line, $parts);
            $id = $parts['ID'] ?? null;

            if (!$id) {
                continue;
            }

            DeviceCommand::where('id', $id)->where('machine_id', $machine->id)->update([
                'status' => 'done',
                'response' => $line,
                'done_at' => now(),
            ]);
        }

        return response('OK', 200);
    }
}
