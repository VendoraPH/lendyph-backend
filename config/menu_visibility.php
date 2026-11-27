<?php

/*
|--------------------------------------------------------------------------
| Menu Visibility (finish run round 6)
|--------------------------------------------------------------------------
|
| Settings → Menu Visibility sits behind an administrator override password.
| The server holds only its bcrypt hash, set per deployment; print one with
| `php artisan menu-visibility:hash-password`. In a .env file, single-quote the
| hash, since it holds `$` signs:
|
|     MENU_VISIBILITY_OVERRIDE_PASSWORD_HASH='$2y$12$...'
|
| While it is unset, or isn't a bcrypt hash, the override answers 503 and
| nobody can open the page (it fails closed).
|
*/

return [

    'override_password_hash' => env('MENU_VISIBILITY_OVERRIDE_PASSWORD_HASH'),

    // How long a right password keeps the page open, for that sign-in only.
    'override_minutes' => (int) env('MENU_VISIBILITY_OVERRIDE_MINUTES', 15),

];
