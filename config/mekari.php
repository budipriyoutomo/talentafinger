<?php

return [
    // Base URL Talenta Data API. Sudah termasuk prefix /v2/talenta/v2.
    //   Prod    : https://api.mekari.com/v2/talenta/v2
    //   Sandbox : https://sandbox-api.mekari.com/v2/talenta/v2
    'base_url' => env('MEKARI_BASE_URL', 'https://api.mekari.com/v2/talenta/v2'),

    // Kredensial HMAC dari Mekari Developer Center (developers.mekari.com).
    'client_id' => env('MEKARI_CLIENT_ID'),
    'client_secret' => env('MEKARI_CLIENT_SECRET'),

    // Khusus endpoint Import Fingerprint (POST /attendance/import-fingerprint).
    // Token diminta via email ke talenta-fingerprint-integration@mekari.com
    // (sertakan Company Name + Company ID). user_id = User ID Talenta.
    'fingerprint_token' => env('MEKARI_FINGERPRINT_TOKEN'),
    'fingerprint_user_id' => env('MEKARI_FINGERPRINT_USER_ID'),

    // FR-09: max requests per minute to Mekari API (avoid HTTP 429)
    'rate_limit' => (int) env('MEKARI_RATE_LIMIT', 60),
];
