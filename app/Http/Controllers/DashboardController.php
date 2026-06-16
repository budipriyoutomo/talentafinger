<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\AttendanceLog;
use App\Models\EmployeeMapping;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $machines = Machine::all();

        $stats = [
            'logs_today' => AttendanceLog::whereDate('created_at', today())->count(),
            'sent_count' => AttendanceLog::where('status_sync', 'sent')->whereDate('created_at', today())->count(),
            'failed_count' => AttendanceLog::where('status_sync', 'failed')->count(),
            'queue_pending' => AttendanceLog::where('status_sync', 'pending')->count(),
            'queue_processing' => 0,
            'queue_failed' => AttendanceLog::where('status_sync', 'failed')->count(),
        ];

        return Inertia::render('Dashboard', [
            'machines' => $machines,
            'stats' => $stats,
        ]);
    }

    public function machines()
    {
        return Inertia::render('Machines', [
            'machines' => Machine::all(),
        ]);
    }

    public function attendanceLogs()
    {
        return Inertia::render('AttendanceLogs', [
            'logs' => AttendanceLog::with('machine')
                ->orderBy('created_at', 'desc')
                ->limit(500)
                ->get()
                ->map(fn($log) => [
                    'id' => $log->id,
                    'machine_id' => $log->machine_id,
                    'biometric_id_lokal' => $log->biometric_id_lokal,
                    'timestamp' => $log->timestamp,
                    'status_sync' => $log->status_sync,
                    'error_message' => $log->error_message,
                    'employee_name' => optional(EmployeeMapping::where('machine_id', $log->machine_id)
                        ->where('biometric_id_lokal', $log->biometric_id_lokal)
                        ->first())->employee_name,
                ]),
            'machines' => Machine::all(),
        ]);
    }

    public function employeeMappings()
    {
        return Inertia::render('EmployeeMappings', [
            'mappings' => EmployeeMapping::all(),
            'machines' => Machine::all(),
        ]);
    }
}
