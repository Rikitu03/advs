<?php

return [
    // WebAuthn RP IDs must be DNS hostnames. Browsers reject IP addresses such
    // as 127.0.0.1, so local development should use http://localhost.
    'relying_party_id' => env('PASSKEYS_RELYING_PARTY_ID', parse_url(config('app.url'), PHP_URL_HOST)),
    'allowed_origins' => array_values(array_filter(array_map(
        static fn (string $origin): string => rtrim(trim($origin), '/'),
        explode(',', env('PASSKEYS_ALLOWED_ORIGINS', config('app.url')))
    ))),
    'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET', config('app.key')),
    'timeout' => 60000,
    'guard' => 'web',
    'middleware' => ['web'],
    'management_middleware' => ['password.confirm'],
    'throttle' => 'throttle:passkeys',
    'redirect' => '/dashboard',
];
