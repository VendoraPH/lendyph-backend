<?php

namespace App\Services\Linda\Model;

/**
 * What a model does for Linda: read one staff question (and up to five
 * earlier ones) and say what it is about. It never answers it: the server
 * writes every answer from its own figures.
 *
 * Provider-neutral. The drivers are `none` (NoneModel), `fake` (FakeModel)
 * and `anthropic` (AnthropicModel); LindaModelFactory picks one.
 */
interface LindaModel
{
    /**
     * Null when there is no model (the none driver).
     *
     * @throws LindaModelUnavailable when a model was asked and couldn't answer
     */
    public function understand(LindaModelRequest $request): ?LindaUnderstanding;
}
