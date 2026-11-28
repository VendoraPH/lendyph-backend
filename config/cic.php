<?php

/*
|--------------------------------------------------------------------------
| CIC reporting (Credit Information Corporation, the 2016 CSDF)
|--------------------------------------------------------------------------
|
| Off by default (`CIC_ENABLED`): while it is off every `/cic` route answers
| 404, as an unknown address would, and the scheduled month-end capture does
| nothing. On for the staging APIs only, through a switch PR.
|
| `CIC_TEST_CAPTURES` lets staff take a manual capture dated today, on any
| day, so staging can make files. The app refuses to boot in production with
| it on (CicServiceProvider).
|
| `CIC_GPG_BINARY` is the GnuPG program the server encrypts with; a missing
| one refuses, never falls back.
|
| The format version is fixed at the 2016 CSDF's "1.0": it is shown, never
| edited.
*/

return [
    'enabled' => filter_var(env('CIC_ENABLED', false), FILTER_VALIDATE_BOOL),
    'test_captures' => filter_var(env('CIC_TEST_CAPTURES', false), FILTER_VALIDATE_BOOL),
    'gpg_binary' => env('CIC_GPG_BINARY', '/usr/bin/gpg') ?: '/usr/bin/gpg',
    'format_version' => '1.0',
];
