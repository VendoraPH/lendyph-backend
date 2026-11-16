<?php

namespace App\Services\Linda;

/**
 * What the server keeps of the conversation the panel sends back (Linda v1
 * plan, "Request size" and "What it sees"): the last few user questions, each
 * paired with the intent of the assistant turn that answered it, oldest first,
 * and the last assistant turn's intent for a follow-up.
 *
 * Assistant content is never read, whatever it holds: the panel sends "" or
 * null, and anything else could be an earlier answer (member rows) or text
 * written to steer the model. An intent the server doesn't know reads as none.
 */
final readonly class LindaHistory
{
    /**
     * @param  list<array{question: string, intent: ?string}>  $earlier
     */
    private function __construct(
        public array $earlier,
        public ?LindaIntent $previousIntent,
    ) {}

    /**
     * @param  array<int, array{role?: mixed, content?: mixed, intent?: mixed}>  $entries  validated history entries
     */
    public static function from(array $entries, int $max): self
    {
        $entries = array_values($entries);
        $earlier = [];
        $previousIntent = null;

        foreach ($entries as $position => $entry) {
            $role = $entry['role'] ?? null;

            if ($role === 'assistant') {
                $previousIntent = self::intent($entry);

                continue;
            }

            if ($role !== 'user') {
                continue;
            }

            $question = trim((string) ($entry['content'] ?? ''));

            if ($question === '') {
                continue;
            }

            $next = $entries[$position + 1] ?? null;

            $earlier[] = [
                'question' => $question,
                'intent' => ($next['role'] ?? null) === 'assistant' ? self::intent($next)?->value : null,
            ];
        }

        return new self($max > 0 ? array_slice($earlier, -$max) : [], $previousIntent);
    }

    /** @param array{intent?: mixed} $entry */
    private static function intent(array $entry): ?LindaIntent
    {
        $intent = $entry['intent'] ?? null;

        return is_string($intent) ? LindaIntent::tryFrom($intent) : null;
    }
}
