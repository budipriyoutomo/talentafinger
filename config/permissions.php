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
        'kiosk.manage' => 'Kelola kiosk QR absensi (tambah, aktivasi, nonaktifkan)',

        'attendance.view' => 'Lihat log absensi',
        'attendance.send' => 'Kirim / kirim ulang log absensi ke Talenta',
        'attendance.review' => 'Setujui / tolak absen karyawan (foto / QR)',
        'attendance.manual.create' => 'Absen manual pakai foto + lokasi (akun karyawan)',
        'attendance.qr.create' => 'Absen QR di outlet (akun karyawan)',

        'employee.view' => 'Lihat master karyawan',
        'employee.manage' => 'Tambah, ubah, hapus karyawan',
        'employee.talenta_sync' => 'Sinkron data karyawan dari Talenta',

        'fingerprint.view' => 'Lihat sidik jari terdaftar di mesin',
        'fingerprint.sync' => 'Tarik & sebar sidik jari antar mesin',
        'fingerprint.delete' => 'Hapus sidik jari / user dari mesin',

        'org.manage' => 'Kelola struktur organisasi (Company, Brand, Outlet)',
        'setting.manage' => 'Ubah pengaturan aplikasi',
        'user.manage' => 'Kelola user dan scope akses',
        'user.registration.review' => 'Setujui / tolak pendaftaran akun karyawan',
        'role.manage' => 'Kelola role & permission-nya',
        'audit.view' => 'Lihat audit log (seluruh aktivitas, tanpa batas outlet)',

        // Bukan aksi, melainkan pembebas sumbu SCOPE: pemiliknya melihat data
        // SEMUA outlet tanpa perlu ditugaskan (role global, mis. HR Pusat).
        'data.all' => 'Akses data semua outlet (tanpa batas scope)',
    ],

    /*
    |---------------------------------------------------------------------------
    | Penjelasan untuk orang awam (form role di /settings)
    |---------------------------------------------------------------------------
    | Ditulis sebagai "apa yang bisa dilakukan di layar", bukan istilah teknis.
    */
    'descriptions' => [
        'machine.view' => 'Membuka menu Machines: daftar mesin, status online, dan jam mesin.',
        'machine.manage' => 'Mengubah data mesin, sinkron jam, menghapus log di perangkat. Berdampak langsung ke mesin.',
        'kiosk.manage' => 'Membuka menu Kiosk QR: mendaftarkan tablet outlet, membuat kode aktivasi, mereset tablet. Hanya untuk outlet yang ditugaskan.',

        'attendance.view' => 'Membuka menu Logs dan Absen per Mesin. Wajib untuk kirim maupun review absensi.',
        'attendance.send' => 'Tombol Kirim / Kirim Ulang absensi ke Talenta.',
        'attendance.review' => 'Tombol Setujui / Tolak pada absen foto & absen QR berstatus "menunggu review" di menu Logs. Hanya untuk outlet yang ditugaskan ke user.',
        'attendance.qr.create' => 'Scan QR di layar outlet lalu kirim selfie + lokasi. Untuk akun yang ditautkan ke data karyawan.',

        'employee.view' => 'Membuka menu Employees (hanya melihat).',
        'employee.manage' => 'Menambah, mengubah, menghapus karyawan, termasuk izin absen foto per karyawan.',
        'employee.talenta_sync' => 'Membuka tab Sinkron Talenta: menarik data karyawan dari Talenta, menyelesaikan konflik, '
            . 'dan mapping cabang ke outlet. Mengubah karyawan di SEMUA outlet, jadi juga butuh "Akses data semua outlet".',

        'fingerprint.view' => 'Membuka menu Sidik Jari.',
        'fingerprint.sync' => 'Menarik sidik jari dari mesin dan menyebarkannya ke mesin lain.',
        'fingerprint.delete' => 'Menghapus sidik jari / user dari mesin. Tidak bisa dibatalkan.',

        'org.manage' => 'Mengubah Company, Brand, Outlet, termasuk titik koordinat outlet untuk absen foto.',
        'setting.manage' => 'Membuka Pengaturan Aplikasi, termasuk kredensial Talenta dan saklar absen foto.',
        'user.manage' => 'Membuat / mengubah user, memilih role, dan menugaskan outlet.',
        'user.registration.review' => 'Membuka menu Pendaftaran Akun: menyetujui / menolak karyawan yang mendaftar sendiri. '
            . 'Hanya karyawan di outlet yang ditugaskan ke user.',
        'role.manage' => 'Membuka halaman ini: membuat dan mengubah role.',
        'audit.view' => 'Membuka Audit Log: riwayat semua perubahan oleh semua user.',

        'data.all' => 'Melihat data SEMUA outlet tanpa perlu ditugaskan. Tanpa ini, user hanya melihat outlet yang ditugaskan di tab User.',
    ],

    /*
    |---------------------------------------------------------------------------
    | Prasyarat antar permission
    |---------------------------------------------------------------------------
    | permission => permission lain yang WAJIB ikut. Mis. tombol Setujui ada di
    | halaman Logs, jadi review tanpa attendance.view tak bisa dipakai. Form role
    | mencentang prasyarat otomatis dan RoleController menambahkannya saat simpan.
    */
    'requires' => [
        'machine.manage' => ['machine.view'],
        'attendance.send' => ['attendance.view'],
        'attendance.review' => ['attendance.view'],
        'employee.manage' => ['employee.view'],
        'employee.talenta_sync' => ['employee.manage', 'data.all'],
        'fingerprint.sync' => ['fingerprint.view'],
        'fingerprint.delete' => ['fingerprint.view'],
    ],

    /*
    |---------------------------------------------------------------------------
    | Permission khusus role sistem
    |---------------------------------------------------------------------------
    | Tak bisa dicentang pada role buatan sendiri. Absen foto & absen QR butuh
    | akun yang ditautkan ke data karyawan: role `employee` memegangnya, dan
    | user role lain yang ditautkan ke karyawan ikut mendapatkannya otomatis
    | (lihat User::permissions).
    */
    'system_only' => [
        'attendance.manual.create',
        'attendance.qr.create',
    ],

    /*
    | Peta role -> permission kini ada di DATABASE (tabel roles + role_permissions)
    | dan dikelola dari /settings tab "Role". Isi awalnya (admin, manager,
    | operator, viewer, employee) ditanam oleh migrasi create_roles_tables.
    */
];
