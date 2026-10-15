<?php

/**
 * KATALOG permission yang dikenal kode. Role mana memegang permission apa
 * disimpan di database (lihat App\Models\Role), bukan di sini.
 *
 * Izin di aplikasi ini punya DUA sumbu yang saling lepas:
 *   1. PERMISSION (role -> permission) -> boleh melakukan AKSI apa (verb).
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
        'attendance.review' => 'Setujui / tolak absen manual pakai foto',
        'attendance.manual.create' => 'Absen manual pakai foto + lokasi (akun karyawan)',

        'employee.view' => 'Lihat master karyawan',
        'employee.manage' => 'Tambah, ubah, hapus karyawan',

        'fingerprint.view' => 'Lihat sidik jari terdaftar di mesin',
        'fingerprint.sync' => 'Tarik & sebar sidik jari antar mesin',
        'fingerprint.delete' => 'Hapus sidik jari / user dari mesin',

        'org.manage' => 'Kelola struktur organisasi (Company, Brand, Outlet)',
        'setting.manage' => 'Ubah pengaturan aplikasi',
        'user.manage' => 'Kelola user dan scope akses',
        'role.manage' => 'Kelola role & permission-nya',
        'audit.view' => 'Lihat audit log (seluruh aktivitas, tanpa batas outlet)',

        // Bukan aksi, melainkan pembebas sumbu SCOPE: pemiliknya melihat data
        // SEMUA outlet tanpa perlu ditugaskan (role global, mis. HR Pusat).
        'data.all' => 'Akses data semua outlet (tanpa batas scope)',
    ],

    /*
    |---------------------------------------------------------------------------
    | Permission khusus role sistem
    |---------------------------------------------------------------------------
    | Tak bisa dicentang pada role buatan sendiri. Absen foto butuh akun yang
    | ditautkan ke data karyawan, dan tautan itu hanya ada pada role `employee`.
    */
    'system_only' => [
        'attendance.manual.create',
    ],

    /*
    | Peta role -> permission kini ada di DATABASE (tabel roles + role_permissions)
    | dan dikelola dari /settings tab "Role". Isi awalnya (admin, manager,
    | operator, viewer, employee) ditanam oleh migrasi create_roles_tables.
    */
];
