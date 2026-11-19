<?php

namespace App\Services\Linda\Model;

/**
 * What a model does for an open question (round 3, LINDA_OPEN_QUESTIONS):
 * write a short answer from a pack of summary figures the server built
 * (LindaFacts). The server checks the text before showing it.
 *
 * Kept apart from LindaModel, so a model that only classifies needn't write.
 * The drivers that write are `fake` (FakeModel) and `anthropic`
 * (AnthropicModel); LindaModelFactory::writer() picks one.
 */
interface LindaWriter
{
    /**
     * @throws LindaModelUnavailable when the model was asked and couldn't answer
     */
    public function write(LindaWriteRequest $request): LindaWriting;
}
