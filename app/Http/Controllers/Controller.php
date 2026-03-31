<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'LendyPH API',
    description: 'REST API for LendyPH Lending Application',
    contact: new OA\Contact(name: 'LendyPH', email: 'support@lendyph.com'),
)]
#[OA\Server(
    url: '/api',
    description: 'API Server',
)]
abstract class Controller
{
    //
}
