<?php

namespace App\Services\Linda\Model;

/**
 * The `none` driver: there is no model, so nothing leaves the server and only
 * the matcher answers.
 */
final class NoneModel implements LindaModel
{
    public function understand(#[\SensitiveParameter] LindaModelRequest $request): ?LindaUnderstanding
    {
        return null;
    }
}
