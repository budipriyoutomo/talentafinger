<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Machine;
use App\Models\AttendanceLog;
use App\Services\AdmsParserService;
use App\Services\IdempotencyService;
use App\Jobs\SendAttendanceToTalenta;

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

        return response($sn, 200);
    }

    public function ingest(Request $request)
    {
        $parsed = $this->parserService->parse($request->getContent());

        if (!$parsed) {
            return response('OK', 200);
        }

        $machine = Machine::where('serial_number', $parsed['sn'])->first();

        if (!$machine) {
            return response('OK', 200);
        }

        $machine->update([
            'last_seen_at' => now(),
            'status' => 'online',
        ]);

        $isDuplicate = $this->idempotencyService->isDuplicate(
            $machine->id,
            $parsed['biometric_id'],
            $parsed['timestamp']
        );

        if ($isDuplicate) {
            $this->idempotencyService->markDuplicate(
                $machine->id,
                $parsed['biometric_id'],
                $parsed['timestamp']
            );
            return response('OK', 200);
        }

        $log = $this->idempotencyService->createLog([
            'machine_id' => $machine->id,
            'biometric_id_lokal' => $parsed['biometric_id'],
            'timestamp' => $parsed['timestamp'],
            'status_sync' => 'pending',
            'payload_raw' => $request->getContent(),
        ]);

        dispatch(new SendAttendanceToTalenta($log->id));

        return response('OK', 200);
    }
}
