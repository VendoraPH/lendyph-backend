<?php

/*
|--------------------------------------------------------------------------
| Loan origination (Phase 6): online applications from public links
|--------------------------------------------------------------------------
|
| `LOAN_ORIGINATION_ENABLED` is the server switch, off by default. While it is
| off no public link takes an application, whatever Settings → Online
| Applications says; staff can still prepare the settings, notices and links.
|
| `LOAN_ORIGINATION_PUBLIC_URL` is the frontend address public links are
| built on (`<url>/apply/<token>`). It must be one of CORS_ALLOWED_ORIGINS,
| exactly; otherwise links have no address (OriginationSwitch::publicUrl()).
|
| Uploads are scanned by `LOAN_ORIGINATION_SCANNER`: `none` (the default)
| marks every file "Not scanned"; `clamav` sends it to clamd over a unix
| socket or TCP and refuses an infected file, or any file while clamd can't
| be reached.
*/

return [
    'enabled' => filter_var(env('LOAN_ORIGINATION_ENABLED', false), FILTER_VALIDATE_BOOL),

    'public_url' => env('LOAN_ORIGINATION_PUBLIC_URL'),

    'scanner' => [
        'driver' => env('LOAN_ORIGINATION_SCANNER', 'none'),
        'clamav' => [
            'socket' => env('LOAN_ORIGINATION_CLAMAV_SOCKET'),
            'host' => env('LOAN_ORIGINATION_CLAMAV_HOST', '127.0.0.1'),
            'port' => (int) env('LOAN_ORIGINATION_CLAMAV_PORT', 3310),
            'timeout' => (float) env('LOAN_ORIGINATION_CLAMAV_TIMEOUT', 10),
        ],
    ],
];
