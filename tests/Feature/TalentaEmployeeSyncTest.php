<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\Setting;
use App\Models\TalentaBranchOutlet;
use App\Models\TalentaEmployee;
use App\Services\TalentaEmployeePullService;
use App\Services\TalentaEmployeeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class TalentaEmployeeSyncTest extends TestCase
{
    use RefreshDatabase;

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
    }

    /** 1 item response GET /employee (v2), hanya field yang dipakai. */
    private function apiRow(int $userId, array $override = []): array
    {
        $row = [
            'user_id' => $userId,
            'sso_id' => "sso-{$userId}",
            'updated_at' => '2026-10-01 10:00:00',
            'personal' => [
                'first_name' => "Karyawan {$userId}",
                'last_name' => '',
                'barcode' => (string) (600000 + $userId),
                'email' => "k{$userId}@example.test",
                'nik' => 'RAHASIA',
                'address' => 'RAHASIA',
            ],
            'employment' => [
                'employee_id' => 'PC-' . (600000 + $userId),
                'organization_name' => 'Kitchen',
                'job_position' => 'Cook',
                'job_level' => 'Staff',
                'employment_status' => 'Permanent',
                'branch_id' => '0',
                'branch' => 'PT Panca - HO',
                'status' => 'Active',
                'resign_date' => '',
                'join_date' => '2020-01-01',
                'end_date' => '',
            ],
        ];

        return array_replace_recursive($row, $override);
    }

    /** Fake 2 halaman list employee. */
    private function fakeTalenta(array $rows, int $perPage = 2): void
    {
        // Fake baru menggantikan yang lama (stub Http::fake pertama selalu menang).
        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));

        $pages = array_chunk($rows, $perPage) ?: [[]];
        $sequence = Http::sequence();
        foreach ($pages as $i => $chunk) {
            $sequence->push([
                'status' => 'success',
                'data' => [
                    'employees' => $chunk,
                    'pagination' => ['current_page' => $i + 1, 'last_page' => count($pages), 'total' => count($rows)],
                ],
            ]);
        }
        Http::fake(['*/employee*' => $sequence]);
    }

    private function pull(): \App\Models\TalentaEmployeeSyncRun
    {
        return app(TalentaEmployeePullService::class)->pull();
    }

    private function sync(): \App\Models\TalentaEmployeeSyncRun
    {
        return app(TalentaEmployeeSyncService::class)->sync();
    }

    public function test_pull_stores_all_pages_without_sensitive_pii(): void
    {
        $this->fakeTalenta([$this->apiRow(1), $this->apiRow(2), $this->apiRow(3)]);

        $run = $this->pull();

        $this->assertSame('success', $run->status);
        $this->assertSame(3, $run->total_fetched);
        $this->assertSame(3, $run->count_changed);
        $this->assertSame(3, TalentaEmployee::pending()->count());

        $row = TalentaEmployee::where('talenta_user_id', '1')->first();
        $this->assertSame('600001', $row->barcode);
        $this->assertSame('PC-600001', $row->employee_code);
        $this->assertSame('0', $row->branch_id);
        $this->assertStringNotContainsString('RAHASIA', json_encode($row->getAttributes()));

        // Signature memakai path lengkap dengan query string.
        Http::assertSent(fn ($req) => str_contains($req->url(), '/employee?limit=50&page=1')
            && str_contains($req->header('Authorization')[0], 'hmac username="cid"'));
    }

    public function test_pull_twice_is_idempotent_and_only_changed_rows_become_pending(): void
    {
        $this->fakeTalenta([$this->apiRow(1), $this->apiRow(2)]);
        $this->pull();
        TalentaEmployee::query()->update(['sync_state' => 'synced']);

        $this->fakeTalenta([$this->apiRow(1), $this->apiRow(2, ['personal' => ['first_name' => 'Nama Baru']])]);
        $run = $this->pull();

        $this->assertSame(2, TalentaEmployee::count());
        $this->assertSame(1, $run->count_changed);
        $this->assertSame(['2'], TalentaEmployee::pending()->pluck('talenta_user_id')->all());
    }

    public function test_pull_failure_marks_run_failed(): void
    {
        Http::fake(['*/employee*' => Http::response(['status' => 'error', 'message' => 'Unauthorized'], 401)]);

        $run = $this->pull();

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('HTTP 401', $run->error_message);
    }

    public function test_rows_missing_from_talenta_are_flagged_but_employees_untouched(): void
    {
        $this->fakeTalenta([$this->apiRow(1), $this->apiRow(2)]);
        $this->pull();
        $this->sync();

        $this->fakeTalenta([$this->apiRow(1)]);
        $this->pull();

        $this->assertNotNull(TalentaEmployee::where('talenta_user_id', '2')->value('missing_at'));
        $this->assertTrue(Employee::where('talenta_user_id', '2')->value('is_active'));
    }

    public function test_sync_creates_new_employee_and_links_existing_manual_one(): void
    {
        // Karyawan input manual, nama terpotong dari mesin, belum tertaut.
        $manual = Employee::create([
            'name' => 'Karyawan',
            'talenta_employee_id' => '600001',
            'biometric_id' => '77',
            'device_privilege' => 14,
            'is_active' => true,
        ]);

        $this->fakeTalenta([$this->apiRow(1), $this->apiRow(2)]);
        $this->pull();
        $run = $this->sync();

        $this->assertSame('success', $run->status);
        $this->assertSame(1, $run->count_created);
        $this->assertSame(1, $run->count_updated);
        $this->assertSame(2, Employee::count(), 'karyawan manual tidak boleh terduplikasi');

        $manual->refresh();
        $this->assertSame('1', $manual->talenta_user_id);
        $this->assertSame('Karyawan 1', $manual->name);
        $this->assertSame('PC-600001', $manual->employee_code);
        $this->assertSame('Cook', $manual->job_position);
        // Field lokal tidak disentuh.
        $this->assertSame('77', $manual->biometric_id);
        $this->assertSame(14, $manual->device_privilege);

        $new = Employee::where('talenta_user_id', '2')->first();
        $this->assertSame('600002', $new->talenta_employee_id);
        $this->assertTrue($new->is_active);
        $this->assertSame(0, $new->outlets()->count());

        $this->assertSame(0, TalentaEmployee::pending()->count());
        $this->assertTrue(AuditLog::where('action', 'employee.talenta_created')->exists());
        $this->assertTrue(AuditLog::where('action', 'employee.talenta_updated')->exists());
    }

    public function test_resigned_employee_is_deactivated_and_resigned_unknown_is_skipped(): void
    {
        $existing = Employee::create(['name' => 'Lama', 'talenta_employee_id' => '600001', 'is_active' => true]);
        $resigned = ['employment' => ['status' => 'Resigned', 'resign_date' => '2026-09-30']];

        $this->fakeTalenta([$this->apiRow(1, $resigned), $this->apiRow(2, $resigned)]);
        $this->pull();
        $run = $this->sync();

        $this->assertSame(1, $run->count_deactivated);
        $this->assertSame(0, $run->count_created);
        $existing->refresh();
        $this->assertFalse($existing->is_active);
        $this->assertSame('2026-09-30', $existing->resign_date->toDateString());
        $this->assertFalse(Employee::where('talenta_user_id', '2')->exists(), 'resign & tak dikenal -> tidak dibuat');
        $this->assertSame('resigned', TalentaEmployee::where('talenta_user_id', '2')->value('match_status'));
    }

    public function test_rehired_employee_is_reactivated_but_manual_deactivation_is_respected(): void
    {
        $rehired = Employee::create(['name' => 'A', 'talenta_employee_id' => '600001', 'talenta_status' => 'Resigned', 'is_active' => false]);
        $manualOff = Employee::create(['name' => 'B', 'talenta_employee_id' => '600002', 'talenta_status' => 'Active', 'is_active' => false]);

        $this->fakeTalenta([$this->apiRow(1), $this->apiRow(2)]);
        $this->pull();
        $this->sync();

        $this->assertTrue($rehired->refresh()->is_active);
        $this->assertFalse($manualOff->refresh()->is_active);
    }

    public function test_different_badge_becomes_conflict_and_is_not_overwritten(): void
    {
        // Employee ID sama, tapi Talenta ID lokal beda dengan barcode Talenta.
        $local = Employee::create(['name' => 'X', 'talenta_employee_id' => '999', 'employee_code' => 'PC-600001', 'is_active' => true]);

        $this->fakeTalenta([$this->apiRow(1)]);
        $this->pull();
        $run = $this->sync();

        $this->assertSame(1, $run->count_conflict);
        $this->assertSame('999', $local->refresh()->talenta_employee_id);
        $this->assertSame('X', $local->name);
        $row = TalentaEmployee::first();
        $this->assertSame('conflict', $row->sync_state);
        $this->assertSame($local->id, $row->employee_id);
        $this->assertStringContainsString('beda dengan barcode', $row->sync_error);
    }

    public function test_resolve_link_with_badge_overwrite_and_ignore(): void
    {
        $local = Employee::create(['name' => 'X', 'talenta_employee_id' => '999', 'employee_code' => 'PC-600001', 'is_active' => true]);
        $this->fakeTalenta([$this->apiRow(1), $this->apiRow(2)]);
        $this->pull();
        $service = app(TalentaEmployeeSyncService::class);

        $service->resolveLink(TalentaEmployee::where('talenta_user_id', '1')->first(), $local, overwriteBadge: true);

        $local->refresh();
        $this->assertSame('1', $local->talenta_user_id);
        $this->assertSame('600001', $local->talenta_employee_id);
        $this->assertSame('Karyawan 1', $local->name);
        $this->assertTrue(AuditLog::where('action', 'employee.talenta_linked')->exists());

        $ignored = TalentaEmployee::where('talenta_user_id', '2')->first();
        $service->ignore($ignored);
        $service->sync();
        $this->assertSame('ignored', $ignored->refresh()->sync_state);
        $this->assertFalse(Employee::where('talenta_user_id', '2')->exists());
    }

    public function test_branch_mapping_fills_outlet_only_when_employee_has_none(): void
    {
        $brand = Brand::create(['company_id' => Company::create(['name' => 'PT'])->id, 'name' => 'Sushi']);
        $ho = Outlet::create(['brand_id' => $brand->id, 'name' => 'HO', 'is_active' => true]);
        $other = Outlet::create(['brand_id' => $brand->id, 'name' => 'STTSM', 'is_active' => true]);
        TalentaBranchOutlet::create(['talenta_branch_id' => '0', 'talenta_branch_name' => 'PT Panca - HO', 'outlet_id' => $ho->id]);

        $placed = Employee::create(['name' => 'P', 'talenta_employee_id' => '600001', 'is_active' => true]);
        $placed->outlets()->attach($other->id);

        $this->fakeTalenta([$this->apiRow(1), $this->apiRow(2)]);
        $this->pull();
        $this->sync();

        $this->assertSame([$other->id], $placed->outlets()->pluck('outlets.id')->all());
        $this->assertSame([$ho->id], Employee::where('talenta_user_id', '2')->first()->outlets()->pluck('outlets.id')->all());
    }

    public function test_talenta_employee_without_barcode_is_created_with_null_badge(): void
    {
        $this->fakeTalenta([$this->apiRow(1, ['personal' => ['barcode' => '']])]);
        $this->pull();
        $this->sync();

        $employee = Employee::where('talenta_user_id', '1')->first();
        $this->assertNotNull($employee);
        $this->assertNull($employee->talenta_employee_id);
    }
}
