<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Machine;
use App\Models\Outlet;
use App\Models\User;
use App\Models\UserScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Halaman "Absen per Mesin" (/employee-presence). */
class EmployeePresenceTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $kopi;
    private Outlet $sate;
    private Machine $kopiA;
    private Machine $kopiB;
    private Machine $sateA;

    protected function setUp(): void
    {
        parent::setUp();

        $maju = Company::create(['name' => 'PT Maju']);
        $this->kopi = Outlet::create([
            'brand_id' => Brand::create(['company_id' => $maju->id, 'name' => 'Kopi'])->id,
            'name' => 'Kopi Sudirman',
        ]);
        $this->sate = Outlet::create([
            'brand_id' => Brand::create(['company_id' => $maju->id, 'name' => 'Sate'])->id,
            'name' => 'Sate Menteng',
        ]);

        $this->kopiA = $this->machineAt($this->kopi, 'SN-KOPI-A');
        $this->kopiB = $this->machineAt($this->kopi, 'SN-KOPI-B');
        $this->sateA = $this->machineAt($this->sate, 'SN-SATE-A');

        $andi = Employee::create(['name' => 'Andi', 'talenta_employee_id' => 'T1', 'biometric_id' => '101']);
        $andi->outlets()->sync([$this->kopi->id, $this->sate->id]);
    }

    private function machineAt(Outlet $outlet, string $serial): Machine
    {
        return Machine::create([
            'serial_number' => $serial,
            'name' => "Mesin {$serial}",
            'outlet_id' => $outlet->id,
            'ip_address' => '192.168.1.10',
        ]);
    }

    private function user(string $role, ?Outlet $outlet = null): User
    {
        $user = User::create([
            'name' => ucfirst($role),
            'email' => $role . '-' . uniqid() . '@adms.local',
            'password' => Hash::make('password'),
            'role' => $role,
        ]);

        if ($outlet) {
            UserScope::create(['user_id' => $user->id, 'scope_type' => 'outlet', 'scope_id' => $outlet->id]);
        }

        return $user;
    }

    private function log(Machine $machine, string $pin, string $timestamp, string $status = 'sent'): void
    {
        AttendanceLog::create([
            'machine_id' => $machine->id,
            'biometric_id_lokal' => $pin,
            'timestamp' => $timestamp,
            'status_sync' => $status,
        ]);
    }

    public function test_satu_baris_per_karyawan_mesin_hari_dengan_masuk_keluar(): void
    {
        $hari = today()->toDateString();
        $kemarin = today()->subDay()->toDateString();
        $this->log($this->kopiA, '101', "$hari 08:00:00");
        $this->log($this->kopiA, '101', "$hari 12:30:00");
        $this->log($this->kopiA, '101', "$hari 17:00:00");
        $this->log($this->kopiB, '101', "$hari 12:00:00");
        // Duplikat tak dihitung.
        $this->log($this->kopiB, '101', "$hari 12:00:05", 'duplicate');
        $this->log($this->kopiA, '101', "$kemarin 07:55:00");
        $this->log($this->kopiA, '101', "$kemarin 16:10:00");

        $this->actingAs($this->user('admin'));

        $this->get('/employee-presence')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('EmployeePresence')
                ->has('rows', 3)
                // Hari ini dulu, dalam satu hari urut jam masuk.
                ->where('rows.0.date', $hari)
                ->where('rows.0.name', 'Andi')
                ->where('rows.0.machine_id', $this->kopiA->id)
                ->where('rows.0.check_in', "$hari 08:00:00")
                ->where('rows.0.check_out', "$hari 17:00:00")
                ->where('rows.0.total', 3)
                ->where('rows.1.machine_id', $this->kopiB->id)
                ->where('rows.1.check_in', "$hari 12:00:00")
                ->where('rows.1.total', 1)
                ->where('rows.2.date', $kemarin)
                ->where('rows.2.check_in', "$kemarin 07:55:00")
                ->where('rows.2.check_out', "$kemarin 16:10:00")
                ->where("machineIdentities.{$this->kopiA->id}.serial_number", 'SN-KOPI-A')
                ->where("machineIdentities.{$this->kopiA->id}.outlet_name", 'Kopi Sudirman')
                ->where("machineIdentities.{$this->kopiA->id}.brand_name", 'Kopi')
                ->where("machineIdentities.{$this->kopiA->id}.company_name", 'PT Maju')
                ->etc());
    }

    public function test_pin_tak_dikenal_tetap_tampil_tanpa_nama(): void
    {
        $this->log($this->kopiA, '999', today()->toDateString() . ' 08:00:00');

        $this->actingAs($this->user('admin'));

        $this->get('/employee-presence')
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.pin', '999')
                ->where('rows.0.name', null)
                ->etc());
    }

    public function test_default_30_hari_terakhir_dan_bisa_diperluas(): void
    {
        $this->log($this->kopiA, '101', today()->subDays(60)->toDateString() . ' 08:00:00');

        $this->actingAs($this->user('admin'));

        $this->get('/employee-presence')
            ->assertInertia(fn ($page) => $page
                ->has('rows', 0)
                ->where('filters.date_from', today()->subDays(29)->toDateString())
                ->etc());

        $this->get('/employee-presence?date_from=' . today()->subDays(90)->toDateString())
            ->assertInertia(fn ($page) => $page->has('rows', 1)->etc());
    }

    public function test_manager_hanya_melihat_mesin_outlet_wewenangnya(): void
    {
        $hari = today()->toDateString();
        $this->log($this->kopiA, '101', "$hari 08:00:00");
        $this->log($this->sateA, '101', "$hari 09:00:00");

        $this->actingAs($this->user('manager', $this->kopi));

        $this->get('/employee-presence')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.machine_id', $this->kopiA->id)
                ->missing("machineIdentities.{$this->sateA->id}")
                ->etc());
    }

    public function test_filter_mesin(): void
    {
        $hari = today()->toDateString();
        $this->log($this->kopiA, '101', "$hari 08:00:00");
        $this->log($this->kopiB, '101', "$hari 09:00:00");

        $this->actingAs($this->user('admin'));

        $this->get('/employee-presence?machine_id=' . $this->kopiB->id)
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.machine_id', $this->kopiB->id)
                ->etc());
    }

    public function test_filter_karyawan_lewat_pin_dan_dropdown_berisi_semua_karyawan(): void
    {
        $budi = Employee::create(['name' => 'Budi', 'talenta_employee_id' => 'T2', 'biometric_id' => '202']);
        Employee::create(['name' => 'Tanpa PIN', 'talenta_employee_id' => 'T3']);
        $andi = Employee::where('biometric_id', '101')->first();

        $hari = today()->toDateString();
        $this->log($this->kopiA, '101', "$hari 08:00:00");
        $this->log($this->kopiA, '202', "$hari 08:05:00");

        $this->actingAs($this->user('admin'));

        $this->get('/employee-presence?employee_id=' . $budi->id)
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.pin', '202')
                ->where('rows.0.employee_id', $budi->id)
                ->where('filters.employee_id', $budi->id)
                // Karyawan tanpa PIN ikut: absen foto/QR tak butuh PIN.
                ->has('employees', 3)
                ->where('employees.0.id', $andi->id)
                ->etc());

        // ID asal-asalan diabaikan (bukan UUID) -> tampil semua.
        $this->get('/employee-presence?employee_id=xyz')
            ->assertInertia(fn ($page) => $page->has('rows', 2)->where('filters.employee_id', null)->etc());
    }

    public function test_filter_sumber_dan_absen_qr_tampil_dengan_outlet(): void
    {
        $andi = Employee::where('biometric_id', '101')->first();
        $hari = today()->toDateString();
        $this->log($this->kopiA, '101', "$hari 08:00:00");
        AttendanceLog::create([
            'source' => AttendanceLog::SOURCE_QR,
            'employee_id' => $andi->id,
            'outlet_id' => $this->sate->id,
            'biometric_id_lokal' => '101',
            'timestamp' => "$hari 12:00:00",
            'status_sync' => 'pending_review',
        ]);
        // Ditolak supervisor -> tidak dihitung.
        AttendanceLog::create([
            'source' => AttendanceLog::SOURCE_QR,
            'employee_id' => $andi->id,
            'outlet_id' => $this->sate->id,
            'biometric_id_lokal' => '101',
            'timestamp' => "$hari 13:00:00",
            'status_sync' => 'rejected',
        ]);

        $this->actingAs($this->user('admin'));

        // Tanpa filter: semua sumber.
        $this->get('/employee-presence')
            ->assertInertia(fn ($page) => $page->has('rows', 2)->etc());

        $this->get('/employee-presence?source=qr')
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.source', 'qr')
                ->where('rows.0.machine_id', null)
                ->where('rows.0.employee_id', $andi->id)
                ->where('rows.0.place.outlet_name', 'Sate Menteng')
                ->where('rows.0.total', 1)
                ->where('filters.source', 'qr')
                ->etc());

        $this->get('/employee-presence?source=machine&employee_id=' . $andi->id)
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.machine_id', $this->kopiA->id)
                ->etc());
    }

    public function test_akun_tanpa_attendance_view_ditolak(): void
    {
        $this->actingAs($this->user('employee'));

        $this->get('/employee-presence')->assertForbidden();
    }
}
