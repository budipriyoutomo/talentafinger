<?php

namespace Tests\Feature;

use App\Jobs\DeleteFingerprintsBulk;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Employee;
use App\Models\FingerprintDeleteJob;
use App\Models\Machine;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\TalentaBranchOutlet;
use App\Models\TalentaEmployee;
use App\Models\User;
use App\Models\UserScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class TalentaEmployeeSyncApiTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $ho;
    private Outlet $other;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushCache();
        Sleep::fake();
        config([
            'mekari.base_url' => 'https://sandbox-api.mekari.test/v2/talenta/v2',
            'mekari.client_id' => 'cid',
            'mekari.client_secret' => 'secret',
        ]);

        $brand = Brand::create(['company_id' => Company::create(['name' => 'PT'])->id, 'name' => 'Sushi']);
        $this->ho = Outlet::create(['brand_id' => $brand->id, 'name' => 'HO', 'is_active' => true]);
        $this->other = Outlet::create(['brand_id' => $brand->id, 'name' => 'STTSM', 'is_active' => true]);
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

    private function talentaRow(array $attrs = []): TalentaEmployee
    {
        return TalentaEmployee::create($attrs + [
            'talenta_user_id' => '1',
            'barcode' => '600001',
            'employee_code' => 'PC-600001',
            'full_name' => 'Ani Talenta',
            'branch_id' => '0',
            'branch_name' => 'PT Panca - HO',
            'status' => 'Active',
            'payload_hash' => 'h1',
            'sync_state' => 'pending',
        ]);
    }

    public function test_sync_endpoints_require_permission_and_full_data_access(): void
    {
        // Manager punya employee.manage tapi tidak employee.talenta_sync.
        $this->actingAs($this->user('manager', $this->ho))
            ->getJson('/api/talenta/employees/summary')->assertForbidden();
        $this->postJson('/api/talenta/employees/run', ['mode' => 'both'])->assertForbidden();
    }

    public function test_admin_sees_summary_and_rows_with_local_comparison(): void
    {
        $local = Employee::create(['name' => 'Ani', 'talenta_employee_id' => '600001', 'is_active' => true]);
        $this->talentaRow(['employee_id' => $local->id, 'match_status' => 'conflict', 'sync_state' => 'conflict', 'sync_error' => 'x']);
        Employee::create(['name' => 'Hanya Lokal', 'talenta_employee_id' => '999', 'is_active' => true]);

        $this->actingAs($this->user('admin'));

        $this->getJson('/api/talenta/employees/summary')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('sync_state.conflict', 1)
            ->assertJsonPath('local_not_in_talenta', 2)
            ->assertJsonPath('running', false);

        $this->getJson('/api/talenta/employees')
            ->assertOk()
            ->assertJsonPath('0.full_name', 'Ani Talenta')
            ->assertJsonPath('0.employee.name', 'Ani')
            ->assertJsonPath('0.employee.linked', false);
    }

    public function test_start_dispatches_run_and_rejects_second_start(): void
    {
        Http::fake(['*/employee*' => Http::response([
            'status' => 'success',
            'data' => ['employees' => [], 'pagination' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]],
        ])]);
        Queue::fake();
        $this->actingAs($this->user('admin'));

        $this->postJson('/api/talenta/employees/run', ['mode' => 'both'])
            ->assertStatus(202)
            ->assertJsonCount(2, 'runs');
        Queue::assertPushed(\App\Jobs\RunTalentaEmployeeSync::class);

        // Run masih queued (job di-fake) -> run kedua ditolak.
        $this->postJson('/api/talenta/employees/run', ['mode' => 'pull'])->assertStatus(409);
        $this->postJson('/api/talenta/employees/run', ['mode' => 'nope'])->assertUnprocessable();
    }

    public function test_link_create_and_ignore_actions(): void
    {
        $local = Employee::create(['name' => 'X', 'talenta_employee_id' => '999', 'is_active' => true]);
        $row = $this->talentaRow(['sync_state' => 'conflict']);
        $this->actingAs($this->user('admin'));

        $this->postJson("/api/talenta/employees/{$row->id}/link", ['employee_id' => $local->id, 'overwrite_badge' => true])
            ->assertOk()
            ->assertJsonPath('row.sync_state', 'synced')
            ->assertJsonPath('row.employee.linked', true);
        $this->assertSame('600001', $local->refresh()->talenta_employee_id);

        // Barcode sudah dipakai -> buat baru ditolak dengan pesan jelas.
        $dup = $this->talentaRow(['talenta_user_id' => '2', 'barcode' => '600001']);
        $this->postJson("/api/talenta/employees/{$dup->id}/create")
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);

        $this->postJson("/api/talenta/employees/{$dup->id}/ignore")
            ->assertOk()
            ->assertJsonPath('row.sync_state', 'ignored');
    }

    public function test_branch_mapping_saved_and_applied_only_to_unplaced_linked_employees(): void
    {
        $unplaced = Employee::create(['name' => 'A', 'talenta_employee_id' => '600001', 'talenta_user_id' => '1', 'is_active' => true]);
        $this->talentaRow(['employee_id' => $unplaced->id, 'sync_state' => 'synced', 'match_status' => 'linked']);
        $placed = Employee::create(['name' => 'B', 'talenta_employee_id' => '600002', 'talenta_user_id' => '2', 'is_active' => true]);
        $placed->outlets()->attach($this->other->id);
        $this->talentaRow(['talenta_user_id' => '2', 'barcode' => '600002', 'employee_id' => $placed->id, 'sync_state' => 'synced']);

        $this->actingAs($this->user('admin'));

        $this->getJson('/api/talenta/employees/branches')
            ->assertOk()
            ->assertJsonPath('branches.0.branch_id', '0')
            ->assertJsonPath('branches.0.employees', 2);

        $this->putJson('/api/talenta/employees/branches', [
            'mappings' => [['branch_id' => '0', 'branch_name' => 'PT Panca - HO', 'outlet_id' => $this->ho->id]],
            'apply' => true,
        ])->assertOk()->assertJsonPath('filled', 1);

        $this->assertSame([$this->ho->id], $unplaced->outlets()->pluck('outlets.id')->all());
        $this->assertSame([$this->other->id], $placed->outlets()->pluck('outlets.id')->all());

        // outlet_id null = hapus mapping.
        $this->putJson('/api/talenta/employees/branches', ['mappings' => [['branch_id' => '0', 'outlet_id' => null]]])->assertOk();
        $this->assertSame(0, TalentaBranchOutlet::count());
    }

    public function test_remove_from_machines_queues_one_job_per_machine(): void
    {
        Queue::fake();
        $m1 = Machine::create(['serial_number' => 'S1', 'name' => 'M1', 'ip_address' => '10.0.0.1', 'outlet_id' => $this->ho->id, 'is_active' => true, 'status' => 'online']);
        $m2 = Machine::create(['serial_number' => 'S2', 'name' => 'M2', 'ip_address' => '10.0.0.2', 'outlet_id' => $this->ho->id, 'is_active' => true, 'status' => 'online']);
        $withPin = Employee::create(['name' => 'Resign', 'talenta_employee_id' => '1', 'biometric_id' => '501', 'is_active' => false]);
        $noPin = Employee::create(['name' => 'Tanpa PIN', 'talenta_employee_id' => '2', 'is_active' => false]);
        $withPin->outlets()->attach($this->ho->id);
        $noPin->outlets()->attach($this->ho->id);

        $this->actingAs($this->user('manager', $this->ho))
            ->postJson('/api/employees/remove-from-machines', [
                'employee_ids' => [$withPin->id, $noPin->id],
                'machine_ids' => [$m1->id, $m2->id],
            ])
            ->assertStatus(202)
            ->assertJsonCount(2, 'jobs')
            ->assertJsonPath('skipped_employees', ['Tanpa PIN']);

        $this->assertSame(2, FingerprintDeleteJob::count());
        $this->assertSame(['501'], FingerprintDeleteJob::first()->pins);
        Queue::assertPushed(DeleteFingerprintsBulk::class, 2);
        $this->assertNotNull($withPin->refresh()->removed_from_machines_at);
        $this->assertNull($noPin->refresh()->removed_from_machines_at);
    }

    public function test_remove_from_machines_rejects_out_of_scope_and_missing_permission(): void
    {
        Queue::fake();
        $machine = Machine::create(['serial_number' => 'S1', 'name' => 'M1', 'ip_address' => '10.0.0.1', 'outlet_id' => $this->other->id, 'is_active' => true, 'status' => 'online']);
        $employee = Employee::create(['name' => 'E', 'talenta_employee_id' => '1', 'biometric_id' => '501']);
        $employee->outlets()->attach($this->ho->id);
        $payload = ['employee_ids' => [$employee->id], 'machine_ids' => [$machine->id]];

        // Mesin di outlet lain -> di luar scope.
        $this->actingAs($this->user('manager', $this->ho))
            ->postJson('/api/employees/remove-from-machines', $payload)->assertForbidden();

        // Viewer tak punya fingerprint.delete.
        $this->actingAs($this->user('viewer', $this->other))
            ->postJson('/api/employees/remove-from-machines', $payload)->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_talenta_linked_employee_can_be_updated_without_badge(): void
    {
        $employee = Employee::create(['name' => 'Baru', 'talenta_user_id' => '9', 'is_active' => true]);
        $manual = Employee::create(['name' => 'Manual', 'talenta_employee_id' => 'M1', 'is_active' => true]);
        $this->actingAs($this->user('admin'));

        $this->putJson("/api/employees/{$employee->id}", ['name' => 'Baru Edit', 'talenta_employee_id' => null])->assertOk();
        $this->putJson("/api/employees/{$manual->id}", ['name' => 'Manual', 'talenta_employee_id' => null])->assertUnprocessable();
    }
}
