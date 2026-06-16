<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Machine;
use App\Models\EmployeeMapping;

class EmployeeMappingSeeder extends Seeder
{
    public function run(): void
    {
        $machine1 = Machine::where('serial_number', 'DEMO001')->first();
        $machine2 = Machine::where('serial_number', 'DEMO002')->first();

        if ($machine1) {
            EmployeeMapping::create([
                'machine_id' => $machine1->id,
                'biometric_id_lokal' => '1001',
                'talenta_employee_id' => 'EMP001',
                'employee_name' => 'Ahmad Rizki',
            ]);

            EmployeeMapping::create([
                'machine_id' => $machine1->id,
                'biometric_id_lokal' => '1002',
                'talenta_employee_id' => 'EMP002',
                'employee_name' => 'Budi Santoso',
            ]);

            EmployeeMapping::create([
                'machine_id' => $machine1->id,
                'biometric_id_lokal' => '1003',
                'talenta_employee_id' => 'EMP003',
                'employee_name' => 'Citra Dewi',
            ]);
        }

        if ($machine2) {
            EmployeeMapping::create([
                'machine_id' => $machine2->id,
                'biometric_id_lokal' => '2001',
                'talenta_employee_id' => 'EMP004',
                'employee_name' => 'Dimas Pratama',
            ]);

            EmployeeMapping::create([
                'machine_id' => $machine2->id,
                'biometric_id_lokal' => '2002',
                'talenta_employee_id' => 'EMP005',
                'employee_name' => 'Eka Putri',
            ]);
        }
    }
}
