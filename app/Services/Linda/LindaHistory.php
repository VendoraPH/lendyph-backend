<?php

namespace App\Services\Linda;

/**
 * What the server keeps of the conversation the panel sends back (Linda v1
 * plan, "Request size" and "What it sees"): the last few user questions, each
 * paired with the intent of the assistant turn that answered it, oldest first,
 * and the intent a follow-up carries on: the last assistant turn's, skipping
 * lookups, so "how about last month?" after a receipt lookup follows the
 * topic asked before it. Separately, the lookup kind of the last assistant
 * turn when that turn was a lookup (`pendingLookup`), so a short reply to
 * "Which member?" is read as that lookup's target and stays on the server.
 *
 * Assistant content is never read, whatever it holds: the panel sends "" or
 * null, and anything else could be an earlier answer (member rows) or text
 * written to steer the model. An intent the server doesn't know reads as none.
 */
final readonly class LindaHistory
{
    /**
     * @param  list<array{question: string, intent: ?string}>  $earlier
     * @param  'member'|'loan'|'receipt'|null  $pendingLookup  the lookup kind of the last assistant turn, when it was a lookup
     */
    private function __construct(
        public array $earlier,
        public ?LindaIntent $previousIntent,
        public ?string $pendingLookup = null,
    ) {}

    /**
     * @param  array<int, array{role?: mixed, content?: mixed, intent?: mixed}>  $entries  validated history entries
     */
    public static function from(array $entries, int $max): self
    {
        $entries = array_values($entries);
        $earlier = [];
        $previousIntent = null;
        $pendingLookup = null;

        foreach ($entries as $position => $entry) {
            $role = $entry['role'] ?? null;

            if ($role === 'assistant') {
                $intent = self::intent($entry);
                $pendingLookup = $intent?->lookupKind();

                if ($intent?->isLookup() !== true) {
                    $previousIntent = $intent;
                }

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

        return new self($max > 0 ? array_slice($earlier, -$max) : [], $previousIntent, $pendingLookup);
    }

    /** @param array{intent?: mixed} $entry */
    private static function intent(array $entry): ?LindaIntent
    {
        $intent = $entry['intent'] ?? null;

        return is_string($intent) ? LindaIntent::tryFrom($intent) : null;
    }
}
