<?php

return [
    'base_url' => env('MEKARI_BASE_URL', 'https://api.mekari.com'),
    'client_id' => env('MEKARI_CLIENT_ID'),
    'client_secret' => env('MEKARI_CLIENT_SECRET'),

    // FR-09: max requests per minute to Mekari API (avoid HTTP 429)
    'rate_limit' => (int) env('MEKARI_RATE_LIMIT', 60),
];
