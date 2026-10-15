<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Halaman /audit-logs (permission audit.view). Audit log TIDAK dibatasi scope
 * outlet — isinya lintas outlet (login, user, role, setting), jadi permission
 * ini hanya untuk role yang memang dipercaya melihat semuanya.
 */
class AuditLogController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request)
    {
        $this->authorizePermission($request, 'audit.view');

        $filters = [
            'action' => $request->query('action') ?: null,
            'user_id' => $request->query('user_id') ?: null,
            'q' => trim((string) $request->query('q')) ?: null,
            'date_from' => $request->query('date_from') ?: null,
            'date_to' => $request->query('date_to') ?: null,
        ];

        $paginator = AuditLog::query()
            // 'user' = semua aksi berawalan 'user.'; 'user.updated' = persis.
            ->when($filters['action'], fn ($q, $a) => str_contains($a, '.')
                ? $q->where('action', $a)
                : $q->where('action', 'like', $a.'.%'))
            ->when($filters['user_id'], fn ($q, $id) => $q->where('user_id', $id))
            // LOWER() supaya tak peka huruf besar/kecil di Postgres maupun sqlite.
            ->when($filters['q'], fn ($q, $s) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(user_name) LIKE ?', ['%'.mb_strtolower($s).'%'])
                ->orWhereRaw('LOWER(target_label) LIKE ?', ['%'.mb_strtolower($s).'%'])
                ->orWhere('ip_address', 'like', "%{$s}%")))
            ->when($filters['date_from'], fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($filters['date_to'], fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
                'user_id' => $log->user_id,
                'user_name' => $log->user_name,
                'action' => $log->action,
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'target_label' => $log->target_label,
                'changes' => $log->changes,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
            ]);

        return Inertia::render('AuditLogs', [
            'logs' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => $filters,
            // Closure: tak dieksekusi saat partial reload (pindah halaman).
            'actions' => fn () => AuditLog::distinct()->orderBy('action')->pluck('action'),
            'users' => fn () => User::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
