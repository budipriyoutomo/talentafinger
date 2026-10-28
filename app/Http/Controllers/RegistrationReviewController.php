<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\RegistrationReviewed;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Halaman /registrations: antrian pendaftaran akun karyawan (lihat
 * RegistrationController). Permission user.registration.review + scope:
 * penyetuju hanya melihat & memutus pendaftar yang karyawannya punya minimal
 * satu outlet dalam scope-nya (Employee::visibleTo).
 */
class RegistrationReviewController extends Controller
{
    private const STATUSES = [User::REG_PENDING, User::REG_UNVERIFIED, User::REG_REJECTED];

    public function index(Request $request)
    {
        $this->authorizePermission($request, 'user.registration.review');

        $status = in_array($request->query('status'), self::STATUSES, true)
            ? $request->query('status')
            : User::REG_PENDING;

        $base = fn () => User::whereIn('employee_id', Employee::visibleTo($request->user())->select('id'));

        $registrations = $base()
            ->with(['employee.outlets:id,name', 'reviewer:id,name'])
            ->where('registration_status', $status)
            ->orderBy($status === User::REG_REJECTED ? 'reviewed_at' : 'registered_at', $status === User::REG_REJECTED ? 'desc' : 'asc')
            ->limit(500)
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'nik' => $u->employee?->employee_code,
                'job_position' => $u->employee?->job_position,
                'outlets' => $u->employee?->outlets->pluck('name')->all() ?? [],
                'employee_active' => (bool) $u->employee?->is_active,
                'registered_at' => $u->registered_at?->format('Y-m-d H:i'),
                'email_verified_at' => $u->email_verified_at?->format('Y-m-d H:i'),
                'reviewed_at' => $u->reviewed_at?->format('Y-m-d H:i'),
                'reviewer' => $u->reviewer?->name,
                'rejection_reason' => $u->rejection_reason,
            ]);

        return Inertia::render('Registrations', [
            'status' => $status,
            'registrations' => $registrations,
            'counts' => $base()->whereIn('registration_status', self::STATUSES)
                ->selectRaw('registration_status, COUNT(*) as total')
                ->groupBy('registration_status')
                ->pluck('total', 'registration_status'),
        ]);
    }

    public function approve(Request $request, string $id)
    {
        $user = $this->findPending($request, $id);

        if (! $user->employee?->is_active) {
            return response()->json(['message' => 'Karyawan ini sudah nonaktif. Tolak pendaftarannya.'], 422);
        }

        $user->forceFill([
            'registration_status' => null,
            'is_active' => true,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ])->save();

        AuditLog::record('user.registration_approved', $user, ['employee' => $user->employee->name]);
        $user->notify(new RegistrationReviewed(true, url('/login')));

        return response()->json(['success' => true, 'message' => "Akun {$user->name} disetujui."]);
    }

    public function reject(Request $request, string $id)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        $user = $this->findPending($request, $id);
        $reason = filled($data['reason'] ?? null) ? trim($data['reason']) : null;

        $user->forceFill([
            'registration_status' => User::REG_REJECTED,
            'is_active' => false,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ])->save();

        AuditLog::record('user.registration_rejected', $user, ['alasan' => $reason]);
        $user->notify(new RegistrationReviewed(false, url('/login'), $reason));

        return response()->json(['success' => true, 'message' => "Pendaftaran {$user->name} ditolak."]);
    }

    /** Pendaftar berstatus pending yang karyawannya dalam scope penyetuju. */
    private function findPending(Request $request, string $id): User
    {
        $user = User::with('employee')->where('registration_status', User::REG_PENDING)->findOrFail($id);

        if (! Employee::visibleTo($request->user())->whereKey($user->employee_id)->exists()) {
            throw new AccessDeniedHttpException('Karyawan ini berada di luar wewenang Anda.');
        }

        return $user;
    }
}
