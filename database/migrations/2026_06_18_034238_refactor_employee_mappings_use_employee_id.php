<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tambah employee_id (nullable dulu supaya data lama bisa diisi).
        Schema::table('employee_mappings', function (Blueprint $table) {
            $table->uuid('employee_id')->nullable()->after('machine_id');
        });

        // 2. Migrasikan data lama: buat 1 employee per talenta_employee_id unik,
        //    lalu tautkan mapping ke employee tersebut.
        foreach (DB::table('employee_mappings')->get() as $row) {
            $employee = DB::table('employees')
                ->where('talenta_employee_id', $row->talenta_employee_id)
                ->first();

            if (!$employee) {
                $employeeId = (string) Str::uuid();
                DB::table('employees')->insert([
                    'id' => $employeeId,
                    'name' => $row->employee_name ?: ('Employee ' . $row->talenta_employee_id),
                    'talenta_employee_id' => $row->talenta_employee_id,
                    'employee_code' => null,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $employeeId = $employee->id;
            }

            DB::table('employee_mappings')->where('id', $row->id)->update([
                'employee_id' => $employeeId,
            ]);
        }

        // 3. Jadikan non-null + pasang foreign key.
        Schema::table('employee_mappings', function (Blueprint $table) {
            $table->uuid('employee_id')->nullable(false)->change();
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });

        // 4. Hapus kolom lama yang sekarang pindah ke tabel employees.
        Schema::table('employee_mappings', function (Blueprint $table) {
            $table->dropColumn(['talenta_employee_id', 'employee_name']);
        });
    }

    public function down(): void
    {
        Schema::table('employee_mappings', function (Blueprint $table) {
            $table->string('talenta_employee_id', 100)->nullable()->after('biometric_id_lokal');
            $table->string('employee_name', 150)->nullable()->after('talenta_employee_id');
        });

        // Kembalikan data dari employees ke kolom lama.
        foreach (DB::table('employee_mappings')->get() as $row) {
            $employee = DB::table('employees')->where('id', $row->employee_id)->first();
            if ($employee) {
                DB::table('employee_mappings')->where('id', $row->id)->update([
                    'talenta_employee_id' => $employee->talenta_employee_id,
                    'employee_name' => $employee->name,
                ]);
            }
        }

        Schema::table('employee_mappings', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropColumn('employee_id');
        });
    }
};
