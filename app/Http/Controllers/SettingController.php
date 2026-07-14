<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * Simpan banyak setting sekaligus. Hanya key yang dikenal yang diproses.
     * Field password yang dikirim kosong DIABAIKAN (tidak menimpa nilai lama),
     * supaya rahasia yang tak ditampilkan ke browser tidak terhapus tak sengaja.
     *
     * Admin-only: setting memuat kredensial Talenta dan sakelar otomatisasi.
     */
    public function update(Request $request)
    {
        $this->authorizePermission($request, 'setting.manage');

        $data = $request->validate([
            'settings' => 'required|array|min:1',
            'settings.*' => 'nullable',
        ]);

        $known = Setting::pluck('type', 'key'); // key => type
        $updated = 0;

        foreach ($data['settings'] as $key => $value) {
            if (! $known->has($key)) {
                continue;
            }

            // Jangan timpa password dengan input kosong.
            if ($known[$key] === 'password' && ($value === null || $value === '')) {
                continue;
            }

            Setting::put($key, $value);
            $updated++;
        }

        return response()->json([
            'success' => true,
            'updated' => $updated,
            'message' => "{$updated} pengaturan disimpan.",
        ]);
    }
}
