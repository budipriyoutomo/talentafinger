<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Machine;

class MachineSeeder extends Seeder
{
    public function run(): void
    {
        Machine::create([
            'serial_number' => 'DEMO001',
            'name' => 'Demo Machine 1',
            'location' => 'Jakarta Pusat',
            'status' => 'offline',
        ]);

        Machine::create([
            'serial_number' => 'DEMO002',
            'name' => 'Demo Machine 2',
            'location' => 'Jakarta Selatan',
            'status' => 'offline',
        ]);
    }
}
