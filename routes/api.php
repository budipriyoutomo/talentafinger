<?php

use Illuminate\Support\Facades\Route;
use App\Models\Machine;
use App\Models\EmployeeMapping;
use App\Models\AttendanceLog;
use Carbon\Carbon;

// API endpoints for dashboard
Route::get('/machines', function () {
    return Machine::all();
});

Route::post('/machines', function (Illuminate\Http\Request $request) {
    return Machine::create($request->validate([
        'serial_number' => 'required|unique:machines',
        'name' => 'required',
        'location' => 'nullable',
    ]));
});

Route::delete('/machines/{id}', function ($id) {
    Machine::findOrFail($id)->delete();
    return response()->json(['success' => true]);
});

Route::get('/employee-mappings', function () {
    return EmployeeMapping::all();
});

Route::post('/employee-mappings', function (Illuminate\Http\Request $request) {
    return EmployeeMapping::create($request->validate([
        'machine_id' => 'required|exists:machines,id',
        'biometric_id_lokal' => 'required',
        'talenta_employee_id' => 'required',
        'employee_name' => 'nullable',
    ]));
});

Route::delete('/employee-mappings/{id}', function ($id) {
    EmployeeMapping::findOrFail($id)->delete();
    return response()->json(['success' => true]);
});

Route::get('/attendance-logs', function (Illuminate\Http\Request $request) {
    $query = AttendanceLog::query();

    if ($request->query('status')) {
        $query->where('status_sync', $request->query('status'));
    }

    if ($request->query('machine_id')) {
        $query->where('machine_id', $request->query('machine_id'));
    }

    if ($request->query('search')) {
        $search = $request->query('search');
        $query->where('biometric_id_lokal', 'like', "%$search%");
    }

    return $query->orderBy('created_at', 'desc')
        ->limit(500)
        ->get();
});

Route::get('/dashboard-stats', function () {
    return [
        'logs_today' => AttendanceLog::whereDate('created_at', today())->count(),
        'sent_count' => AttendanceLog::where('status_sync', 'sent')->whereDate('created_at', today())->count(),
        'failed_count' => AttendanceLog::where('status_sync', 'failed')->count(),
        'duplicate_count' => AttendanceLog::where('status_sync', 'duplicate')->count(),
        'queue_pending' => AttendanceLog::where('status_sync', 'pending')->count(),
        'queue_processing' => 0,
        'queue_failed' => AttendanceLog::where('status_sync', 'failed')->count(),
    ];
});
