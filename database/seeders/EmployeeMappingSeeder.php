<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Machine;
use App\Models\Employee;
use App\Models\EmployeeMapping;

class EmployeeMappingSeeder extends Seeder
{
    public function run(): void
    {
        $machine1 = Machine::where('serial_number', 'DEMO001')->first();
        $machine2 = Machine::where('serial_number', 'DEMO002')->first();

        // Master karyawan.
        $ahmad = Employee::firstOrCreate(
            ['talenta_employee_id' => 'EMP001'],
            ['name' => 'Ahmad Rizki']
        );
        $budi = Employee::firstOrCreate(
            ['talenta_employee_id' => 'EMP002'],
            ['name' => 'Budi Santoso']
        );

        if ($machine1) {
            EmployeeMapping::firstOrCreate([
                'machine_id' => $machine1->id,
                'biometric_id_lokal' => '1001',
            ], ['employee_id' => $ahmad->id]);

            EmployeeMapping::firstOrCreate([
                'machine_id' => $machine1->id,
                'biometric_id_lokal' => '1002',
            ], ['employee_id' => $budi->id]);
        }

        // Contoh: karyawan yang sama (Ahmad) juga terdaftar di mesin kedua
        // dengan biometric ID yang sama.
        if ($machine2) {
            EmployeeMapping::firstOrCreate([
                'machine_id' => $machine2->id,
                'biometric_id_lokal' => '1001',
            ], ['employee_id' => $ahmad->id]);
        }
    }
}
