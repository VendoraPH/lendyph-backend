<?php

namespace App\Services\Linda\Model;

/**
 * Everything a model sees to write an open answer: the question, the topics
 * (intents) of up to five earlier turns, as LindaModelRequest keeps them,
 * the facts pack (LindaFacts::build(): counts, peso totals, rates, periods
 * and branch names only), and the model id.
 */
final class LindaWriteRequest
{
    /** @var list<string> */
    public readonly array $topics;

    /**
     * @param  array<int, mixed>  $topics  the intents of earlier turns, oldest first
     * @param  array<string, mixed>  $facts
     */
    public function __construct(
        #[\SensitiveParameter] public readonly string $question,
        array $topics,
        public readonly array $facts,
        public readonly string $model,
    ) {
        $this->topics = (new LindaModelRequest($question, $topics, $model))->topics;
    }
}
