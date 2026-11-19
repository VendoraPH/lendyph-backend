<?php

use App\Providers\AmlaServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\LoanOriginationServiceProvider;
use App\Providers\PortalServiceProvider;
use App\Providers\SavingsNoticesServiceProvider;

return [
    AppServiceProvider::class,
    PortalServiceProvider::class,
    SavingsNoticesServiceProvider::class,
    AmlaServiceProvider::class,
    LoanOriginationServiceProvider::class,
];
