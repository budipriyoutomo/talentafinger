<?php

return [
    // Timezone perangkat (dipakai DeviceCommandService saat sync jam).
    'device_timezone' => env('ADMS_DEVICE_TIMEZONE', 'Asia/Jakarta'),

    // Binary Python untuk menjalankan skrip pyzk (sync sidik jari via TCP 4370).
    // Default mengikuti OS: Windows punya 'python', Debian/container hanya
    // punya 'python3' (tidak ada alias 'python' tanpa paket python-is-python3).
    'python_bin' => env('ADMS_PYTHON_BIN', PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3'),

    // Port SDK default mesin ZKTeco.
    'sdk_port' => (int) env('ADMS_SDK_PORT', 4370),

    // Disk penyimpanan foto absen (absen foto & QR): 'local' (storage/app/private)
    // atau 'idcloudhost' (Object Storage S3). Foto lama di 'local' tetap terbaca.
    'photo_disk' => env('ATTENDANCE_PHOTO_DISK', 'local'),
];
