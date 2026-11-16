<?php

namespace App\Services\Linda\Model;

use App\Services\Linda\LindaIntent;

/**
 * Everything a model sees for one question: the question, up to five earlier
 * questions with the intent each was given (oldest first), and the model id
 * to ask. Never an earlier answer, a member row or a figure.
 */
final class LindaModelRequest
{
    /** The most earlier questions a model sees. */
    public const EARLIER_MAX = 5;

    /** @var list<array{question: string, intent: ?string}> */
    public readonly array $earlier;

    /**
     * @param  list<array{question: string, intent: LindaIntent|string|null}>  $earlier  oldest first
     */
    public function __construct(
        public readonly string $question,
        array $earlier,
        public readonly string $model,
    ) {
        $kept = [];

        foreach ($earlier as $entry) {
            $text = trim((string) ($entry['question'] ?? ''));

            if ($text === '') {
                continue;
            }

            $intent = $entry['intent'] ?? null;
            $intent = $intent instanceof LindaIntent ? $intent : LindaIntent::tryFrom((string) $intent);

            $kept[] = ['question' => $text, 'intent' => $intent?->value];
        }

        $this->earlier = array_slice($kept, -self::EARLIER_MAX);
    }

    /** The intent of the latest earlier question, when it had one. */
    public function previousIntent(): ?LindaIntent
    {
        $last = $this->earlier === [] ? null : $this->earlier[count($this->earlier) - 1];

        return $last === null || $last['intent'] === null ? null : LindaIntent::from($last['intent']);
    }
}
