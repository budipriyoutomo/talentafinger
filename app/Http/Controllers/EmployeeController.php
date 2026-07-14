<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Machine;
use App\Services\FingerprintMasterService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master karyawan.
 *
 * Karyawan bisa berada di BANYAK outlet, jadi aturan mainnya lebih halus dari
 * modul lain: seorang manajer hanya boleh mengutak-atik keanggotaan outlet YANG
 * JADI WEWENANGNYA. Outlet lain pada karyawan yang sama tak boleh ia sentuh —
 * lihat resolveOutletIds().
 */
class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Employee::class);

        return Employee::visibleTo($request->user())->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $this->authorize('create', Employee::class);

        $data = $request->validate([
            'name' => 'required',
            'talenta_employee_id' => 'required|unique:employees,talenta_employee_id',
            'employee_code' => 'nullable',
            'biometric_id' => 'nullable|string|max:50',
            'outlet_ids' => 'nullable|array',
            'outlet_ids.*' => 'exists:outlets,id',
            'device_privilege' => 'nullable|integer|min:0|max:14',
            'is_active' => 'boolean',
        ]);

        $outletIds = array_values(array_unique($data['outlet_ids'] ?? []));
        unset($data['outlet_ids']);

        // Karyawan tanpa outlet = tak terlihat oleh siapa pun kecuali admin, jadi
        // non-admin wajib menempatkannya (assertOutletInScope menolak null).
        if ($outletIds === []) {
            $this->assertOutletInScope($request, null);
        }
        $this->assertOutletsInScope($request, $outletIds);

        $employee = Employee::create($data);
        $employee->outlets()->sync($outletIds);

        return $employee->load('outlets');
    }

    public function update(Request $request, string $id)
    {
        $employee = Employee::findOrFail($id);
        $this->authorize('update', $employee);

        $data = $request->validate([
            'name' => 'required',
            'talenta_employee_id' => ['required', Rule::unique('employees', 'talenta_employee_id')->ignore($id)],
            'employee_code' => 'nullable',
            'biometric_id' => 'nullable|string|max:50',
            'outlet_ids' => 'nullable|array',
            'outlet_ids.*' => 'exists:outlets,id',
            'device_privilege' => 'nullable|integer|min:0|max:14',
            'is_active' => 'boolean',
        ]);

        $hasOutletKey = array_key_exists('outlet_ids', $data);
        $requested = array_values(array_unique($data['outlet_ids'] ?? []));
        unset($data['outlet_ids']);

        $employee->update($data);

        // Hanya sinkronkan outlet bila key dikirim (hindari mengosongkan tak sengaja).
        if ($hasOutletKey) {
            $this->assertOutletsInScope($request, $requested);
            $employee->outlets()->sync($this->resolveOutletIds($request, $employee, $requested));
        }

        return $employee->load('outlets');
    }

    public function destroy(string $id)
    {
        $employee = Employee::findOrFail($id);
        $this->authorize('delete', $employee);

        $employee->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Impor user dari mesin (halaman /fingerprints) -> master karyawan.
     * Kunci dedup: biometric_id (PIN). Opsional langsung tarik template ke DB.
     */
    public function importFromMachine(Request $request, FingerprintMasterService $fingerprints)
    {
        $this->authorize('create', Employee::class);

        $data = $request->validate([
            'biometric_id' => 'required|string|max:50',
            'name' => 'nullable|string|max:150',
            'source_machine_id' => 'required|exists:machines,id',
            'capture' => 'boolean',
        ]);

        $source = Machine::findOrFail($data['source_machine_id']);
        $this->authorize('view', $source);

        $employee = Employee::where('biometric_id', $data['biometric_id'])->first();
        $created = false;

        if (! $employee) {
            $employee = Employee::create([
                'name' => $data['name'] ?: ('User ' . $data['biometric_id']),
                'biometric_id' => $data['biometric_id'],
                'is_active' => true,
            ]);
            $created = true;

            // Karyawan hasil impor langsung ditempatkan di outlet mesin sumbernya,
            // supaya tidak lahir sebagai karyawan tanpa outlet yang cuma terlihat
            // admin. Mesin tanpa outlet hanya bisa diakses admin (policy `view`),
            // jadi kasus itu memang hanya terjadi pada admin.
            if ($source->outlet_id) {
                $employee->outlets()->sync([$source->outlet_id]);
            }
        } elseif (! empty($data['name']) && $employee->name !== $data['name']) {
            // Selaraskan nama dari mesin bila berubah.
            $this->authorize('update', $employee);
            $employee->update(['name' => $data['name']]);
        }

        // Opsional: sekalian tarik sidik jari ke DB (sumber kebenaran).
        $capture = null;
        if ($request->boolean('capture')) {
            $this->authorizePermission($request, 'fingerprint.sync');
            $capture = $fingerprints->captureFromMachine($employee, $source);
        }

        return response()->json([
            'ok' => true,
            'created' => $created,
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'biometric_id' => $employee->biometric_id,
            ],
            'capture' => $capture,
        ]);
    }

    /**
     * Gabungkan outlet yang diminta dengan outlet karyawan YANG DI LUAR scope user.
     *
     * Tanpa ini, seorang manajer yang menyimpan form karyawan lintas-outlet akan
     * diam-diam mencopot karyawan itu dari outlet manajer lain — sebab form-nya
     * memang hanya menampilkan outlet yang ia lihat. Outlet di luar wewenangnya
     * karena itu dipertahankan apa adanya.
     */
    private function resolveOutletIds(Request $request, Employee $employee, array $requested): array
    {
        $allowed = $request->user()->scopedOutletIds();

        if ($allowed === null) {
            return $requested;
        }

        $preserved = $employee->outlets()
            ->whereNotIn('outlets.id', $allowed)
            ->pluck('outlets.id')
            ->all();

        return array_values(array_unique([...$requested, ...$preserved]));
    }
}
