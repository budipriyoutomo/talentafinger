<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;
use App\Models\UserScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeeAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $kopi;
    private Outlet $sate;

    protected function setUp(): void
    {
        parent::setUp();

        $maju = Company::create(['name' => 'PT Maju']);
        $lain = Company::create(['name' => 'PT Lain']);

        $this->kopi = Outlet::create([
            'brand_id' => Brand::create(['company_id' => $maju->id, 'name' => 'Kopi'])->id,
            'name' => 'Kopi Sudirman',
        ]);
        $this->sate = Outlet::create([
            'brand_id' => Brand::create(['company_id' => $lain->id, 'name' => 'Sate'])->id,
            'name' => 'Sate Menteng',
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
            UserScope::create([
                'user_id' => $user->id,
                'scope_type' => 'outlet',
                'scope_id' => $outlet->id,
            ]);
        }

        return $user;
    }

    private function employee(string $name, string $talentaId, array $outlets = []): Employee
    {
        $e = Employee::create(['name' => $name, 'talenta_employee_id' => $talentaId]);
        $e->outlets()->sync(collect($outlets)->pluck('id')->all());

        return $e;
    }

    public function test_daftar_karyawan_disaring_ke_outlet_dalam_scope(): void
    {
        $this->employee('Andi', 'T1', [$this->kopi]);
        $this->employee('Budi', 'T2', [$this->sate]);
        $this->employee('Yatim', 'T3');

        $this->actingAs($this->user('manager', $this->kopi));

        $this->getJson('/api/employees')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'Andi'])
            ->assertJsonMissing(['name' => 'Budi'])
            ->assertJsonMissing(['name' => 'Yatim']);
    }

    public function test_tidak_bisa_mengubah_karyawan_outlet_lain(): void
    {
        $budi = $this->employee('Budi', 'T2', [$this->sate]);

        $this->actingAs($this->user('manager', $this->kopi));

        $this->putJson("/api/employees/{$budi->id}", [
            'name' => 'Dibajak',
            'talenta_employee_id' => 'T2',
        ])->assertStatus(403);

        $this->assertDatabaseHas('employees', ['id' => $budi->id, 'name' => 'Budi']);
    }

    public function test_tidak_bisa_menempatkan_karyawan_ke_outlet_di_luar_wewenang(): void
    {
        $this->actingAs($this->user('manager', $this->kopi));

        // Mencoba "mencuri" outlet orang lain lewat outlet_ids.
        $this->postJson('/api/employees', [
            'name' => 'Titipan',
            'talenta_employee_id' => 'T9',
            'outlet_ids' => [$this->sate->id],
        ])->assertStatus(403);

        $this->assertDatabaseMissing('employees', ['talenta_employee_id' => 'T9']);
    }

    public function test_non_admin_wajib_menempatkan_karyawan_di_outlet(): void
    {
        // Karyawan tanpa outlet hanya terlihat admin -> non-admin tak boleh membuatnya.
        $this->actingAs($this->user('manager', $this->kopi));

        $this->postJson('/api/employees', [
            'name' => 'Tanpa Outlet',
            'talenta_employee_id' => 'T8',
        ])->assertStatus(403);
    }

    public function test_manager_tidak_mencopot_karyawan_dari_outlet_manajer_lain(): void
    {
        // Karyawan lintas-outlet: Kopi (wewenang manajer ini) + Sate (bukan).
        $lintas = $this->employee('Lintas', 'T5', [$this->kopi, $this->sate]);

        $this->actingAs($this->user('manager', $this->kopi));

        // Form manajer Kopi hanya menampilkan outlet Kopi. Ia mengosongkan
        // outlet -> hanya keanggotaan Kopi yang boleh lepas; Sate harus bertahan.
        $this->putJson("/api/employees/{$lintas->id}", [
            'name' => 'Lintas',
            'talenta_employee_id' => 'T5',
            'outlet_ids' => [],
        ])->assertOk();

        $sisa = $lintas->fresh()->outlets->pluck('id')->all();
        $this->assertSame([$this->sate->id], $sisa);
    }

    public function test_manager_tidak_bisa_menghapus_karyawan_lintas_outlet(): void
    {
        // Hapus = buang dari SEMUA outlet, termasuk milik orang lain -> ditolak.
        $lintas = $this->employee('Lintas', 'T5', [$this->kopi, $this->sate]);

        $this->actingAs($this->user('manager', $this->kopi));

        $this->deleteJson("/api/employees/{$lintas->id}")->assertStatus(403);
        $this->assertDatabaseHas('employees', ['id' => $lintas->id]);
    }

    public function test_manager_boleh_menghapus_karyawan_yang_seluruh_outletnya_miliknya(): void
    {
        $andi = $this->employee('Andi', 'T1', [$this->kopi]);

        $this->actingAs($this->user('manager', $this->kopi));

        $this->deleteJson("/api/employees/{$andi->id}")->assertOk();
        $this->assertDatabaseMissing('employees', ['id' => $andi->id]);
    }

    public function test_operator_boleh_lihat_tapi_tak_boleh_ubah(): void
    {
        $andi = $this->employee('Andi', 'T1', [$this->kopi]);

        $this->actingAs($this->user('operator', $this->kopi));

        $this->getJson('/api/employees')->assertOk()->assertJsonCount(1);
        $this->putJson("/api/employees/{$andi->id}", [
            'name' => 'Diubah',
            'talenta_employee_id' => 'T1',
        ])->assertStatus(403);
    }

    public function test_admin_bebas_mengelola_seluruh_outlet(): void
    {
        $this->actingAs($this->user('admin'));

        $this->postJson('/api/employees', [
            'name' => 'Lintas',
            'talenta_employee_id' => 'T7',
            'outlet_ids' => [$this->kopi->id, $this->sate->id],
        ])->assertCreated();

        $this->getJson('/api/employees')->assertOk()->assertJsonCount(1);
    }
}
