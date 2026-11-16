<?php

namespace App\Services\Linda;

/**
 * The local guard (security review S3), run after the matcher misses and
 * before any model call: a question that names a record, asks to look one up,
 * or asks about one person's balance, loan or payments is never sent to the
 * model, and neither is an earlier question that did.
 *
 * It reads questions with LindaLookup, so whatever the lookup parser treats
 * as a lookup is guarded with the same intent: record numbers, emails, mobile
 * numbers, long digit runs, lookup wording, and person markers in English,
 * Tagalog and Bisaya. Dates, amounts and the period words the matcher reads
 * never trip it.
 */
final class LindaGuard
{
    /**
     * The lookup intent a question asks for, or null when it isn't one.
     */
    public static function check(#[\SensitiveParameter] string $question): ?LindaIntent
    {
        return LindaLookup::parse($question)?->intent;
    }

    /**
     * The earlier questions without the ones the guard flags, each dropped
     * with its intent, so a lookup kept local never reaches the model later
     * as an earlier question.
     *
     * @param  list<array{question: string, intent: ?string}>  $earlier
     * @return list<array{question: string, intent: ?string}>
     */
    public static function withoutLookups(#[\SensitiveParameter] array $earlier): array
    {
        return array_values(array_filter($earlier, fn (array $entry): bool => self::check((string) $entry['question']) === null));
    }
}
