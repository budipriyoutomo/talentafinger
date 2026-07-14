<?php

/**
 * SATU-SATUNYA sumber kebenaran untuk "role X boleh melakukan apa".
 *
 * Izin di aplikasi ini punya DUA sumbu yang saling lepas:
 *   1. PERMISSION (file ini) -> boleh melakukan AKSI apa (verb).
 *   2. SCOPE (tabel user_scopes) -> boleh menyentuh DATA outlet mana (baris).
 * Keduanya harus lolos. Contoh: manager dengan scope Brand A boleh mengubah
 * karyawan, tapi hanya karyawan yang ada di outlet milik Brand A.
 *
 * Admin selalu lolos kedua sumbu (lihat AuthServiceProvider::boot / Gate::before)
 * dan tidak dibatasi outlet mana pun.
 */
return [

    /*
    |---------------------------------------------------------------------------
    | Daftar permission yang dikenal
    |---------------------------------------------------------------------------
    | Key dipakai di kode (Gate/policy), value cuma label untuk manusia (UI &
    | dokumentasi). Menambah baris di sini TIDAK memberi akses ke siapa pun
    | sampai dimasukkan ke salah satu role di bawah.
    */
    'permissions' => [
        'machine.view' => 'Lihat daftar mesin & statusnya',
        'machine.manage' => 'Tambah, ubah, hapus mesin; sync jam; hapus log di perangkat',

        'attendance.view' => 'Lihat log absensi',
        'attendance.send' => 'Kirim / kirim ulang log absensi ke Talenta',

        'employee.view' => 'Lihat master karyawan',
        'employee.manage' => 'Tambah, ubah, hapus karyawan',

        'fingerprint.view' => 'Lihat sidik jari terdaftar di mesin',
        'fingerprint.sync' => 'Tarik & sebar sidik jari antar mesin',
        'fingerprint.delete' => 'Hapus sidik jari / user dari mesin',

        'org.manage' => 'Kelola struktur organisasi (Company, Brand, Outlet)',
        'setting.manage' => 'Ubah pengaturan aplikasi',
        'user.manage' => 'Kelola user, role, dan scope akses',
    ],

    /*
    |---------------------------------------------------------------------------
    | Peta role -> permission
    |---------------------------------------------------------------------------
    | Urutan = dari hak paling tinggi ke paling rendah. '*' berarti semua izin.
    | Hanya `admin` yang berhak menyentuh user, setting, dan struktur organisasi,
    | karena ketiganya bisa dipakai untuk memperluas hak akses diri sendiri.
    */
    'roles' => [

        // Akses penuh + kelola user/setting/organisasi. Tidak dibatasi outlet.
        'admin' => ['*'],

        // Penanggung jawab operasional di outlet-nya: kelola karyawan & sidik
        // jari, kirim ulang absensi. Tidak boleh mengubah perangkat keras.
        'manager' => [
            'machine.view',
            'attendance.view',
            'attendance.send',
            'employee.view',
            'employee.manage',
            'fingerprint.view',
            'fingerprint.sync',
            'fingerprint.delete',
        ],

        // Menjalankan operasi harian, tapi tidak boleh menghapus apa pun.
        'operator' => [
            'machine.view',
            'attendance.view',
            'attendance.send',
            'employee.view',
            'fingerprint.view',
            'fingerprint.sync',
        ],

        // Baca saja.
        'viewer' => [
            'machine.view',
            'attendance.view',
            'employee.view',
            'fingerprint.view',
        ],
    ],
];
