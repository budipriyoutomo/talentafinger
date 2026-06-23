<?php

return [
    // Timezone perangkat (dipakai DeviceCommandService saat sync jam).
    'device_timezone' => env('ADMS_DEVICE_TIMEZONE', 'Asia/Jakarta'),

    // Binary Python untuk menjalankan skrip pyzk (sync sidik jari via TCP 4370).
    // Windows: 'python'. Linux VPS: biasanya 'python3'.
    'python_bin' => env('ADMS_PYTHON_BIN', 'python'),

    // Port SDK default mesin ZKTeco.
    'sdk_port' => (int) env('ADMS_SDK_PORT', 4370),
];
