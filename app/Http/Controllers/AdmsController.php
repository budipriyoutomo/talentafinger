<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Machine;
use App\Models\DeviceCommand;
use App\Models\BiometricTemplate;
use App\Services\AdmsParserService;
use App\Services\IdempotencyService;
use Illuminate\Support\Facades\Log;

class AdmsController extends Controller
{
    public function __construct(
        private AdmsParserService $parserService,
        private IdempotencyService $idempotencyService,
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

        $machine->update([
            'last_seen_at' => now(),
            'status' => 'online',
        ]);

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

        $machine->update([
            'last_seen_at' => now(),
            'status' => 'online',
        ]);

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
            $isDuplicate = $this->idempotencyService->isDuplicate(
                $machine->id,
                $rec['biometric_id'],
                $rec['timestamp']
            );

            if ($isDuplicate) {
                $this->idempotencyService->markDuplicate(
                    $machine->id,
                    $rec['biometric_id'],
                    $rec['timestamp']
                );
                continue;
            }

            // Simpan sebagai 'pending'. Pengiriman ke Mekari Talenta dilakukan
            // manual via tombol di aplikasi (AttendanceController@send).
            $this->idempotencyService->createLog([
                'machine_id' => $machine->id,
                'biometric_id_lokal' => $rec['biometric_id'],
                'timestamp' => $rec['timestamp'],
                'status_sync' => 'pending',
                'payload_raw' => $rec['raw'],
            ]);
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

        $machine->update([
            'last_seen_at' => now(),
            'status' => 'online',
        ]);

        if (!$machine->is_active) {
            return response('OK', 200);
        }

        $commands = DeviceCommand::where('machine_id', $machine->id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->get();

        if ($commands->isEmpty()) {
            return response('OK', 200);
        }

        $lines = [];
        foreach ($commands as $cmd) {
            $lines[] = "C:{$cmd->id}:{$cmd->command}";
            $cmd->update(['status' => 'sent', 'sent_at' => now()]);
        }

        return response(implode("\n", $lines) . "\n", 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Mesin melaporkan hasil eksekusi perintah ke endpoint ini.
     * Body contoh: "ID=12&Return=0&CMD=OPTIONS".
     */
    public function devicecmd(Request $request)
    {
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

            DeviceCommand::where('id', $id)->update([
                'status' => 'done',
                'response' => $line,
                'done_at' => now(),
            ]);
        }

        return response('OK', 200);
    }
}
