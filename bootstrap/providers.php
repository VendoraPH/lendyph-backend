<?php

use App\Providers\AmlaServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CicRecordsServiceProvider;
use App\Providers\CicServiceProvider;
use App\Providers\LoanOriginationServiceProvider;
use App\Providers\PortalServiceProvider;
use App\Providers\ReceiptShareServiceProvider;
use App\Providers\SavingsNoticesServiceProvider;

return [
    AppServiceProvider::class,
    PortalServiceProvider::class,
    SavingsNoticesServiceProvider::class,
    AmlaServiceProvider::class,
    LoanOriginationServiceProvider::class,
    ReceiptShareServiceProvider::class,
    CicServiceProvider::class,
    CicRecordsServiceProvider::class,
];
