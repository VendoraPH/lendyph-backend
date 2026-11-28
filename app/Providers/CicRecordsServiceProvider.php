<?php

namespace App\Providers;

use App\Services\Cic\CicValidator;
use App\Services\Cic\Contracts\ValidatesCicCapture;
use Illuminate\Support\ServiceProvider;

/**
 * The CIC records slice's wiring: CicValidator is the server's authoritative
 * implementation of the capture checks the submissions slice calls before a
 * file is generated.
 */
class CicRecordsServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ValidatesCicCapture::class => CicValidator::class,
    ];
}
