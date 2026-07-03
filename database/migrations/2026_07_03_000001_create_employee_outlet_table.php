<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1 karyawan kini boleh terdaftar di BANYAK outlet. Tabel pivot employee_outlet
 * menggantikan kolom tunggal employees.outlet_id. Data outlet_id lama disalin
 * ke pivot lalu kolomnya dibuang supaya tak ada dua sumber kebenaran.
 *
 * Outlet dihapus -> baris pivot ikut terhapus (asosiasi hilang, karyawan tetap).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_outlet', function (Blueprint $table) {
            $table->uuid('employee_id');
            $table->uuid('outlet_id');
            $table->timestamps();

            $table->primary(['employee_id', 'outlet_id']);
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
        });

        // Salin penempatan tunggal yang sudah ada ke pivot.
        $now = now();
        DB::table('employees')
            ->whereNotNull('outlet_id')
            ->select('id', 'outlet_id')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($now) {
                $insert = $rows->map(fn ($e) => [
                    'employee_id' => $e->id,
                    'outlet_id' => $e->outlet_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();
                if ($insert) {
                    DB::table('employee_outlet')->insert($insert);
                }
            });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['outlet_id']);
            $table->dropColumn('outlet_id');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->uuid('outlet_id')->nullable()->after('employee_code');
            $table->foreign('outlet_id')->references('id')->on('outlets')->nullOnDelete();
        });

        // Kembalikan outlet pertama (jika ada) ke kolom tunggal.
        $pivots = DB::table('employee_outlet')->orderBy('employee_id')->get()
            ->groupBy('employee_id');
        foreach ($pivots as $employeeId => $rows) {
            DB::table('employees')->where('id', $employeeId)
                ->update(['outlet_id' => $rows->first()->outlet_id]);
        }

        Schema::dropIfExists('employee_outlet');
    }
};
