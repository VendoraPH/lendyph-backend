<?php

namespace App\Services\Linda;

/**
 * The local guard (security review S3): the lookup parser's verdict on one
 * question, as a lookup intent. Whatever LindaLookup::parse() treats as a
 * lookup (record numbers, emails, phone numbers, long digit runs, lookup
 * wording, person markers and names beside money words, in English, Tagalog
 * and Bisaya) the guard catches with the same intent. Dates, amounts and the
 * period words the matcher reads never trip it.
 *
 * It is one of several steps that keep names and numbers on the server
 * (LindaAssistant): the matcher, then the Loan No. check (LindaLoanNumbers,
 * which reads the deployment's imported Loan Nos. and fails closed), then
 * the parser, then a reply to a lookup
 * turn (LindaLookup::reply()), then the full-name check (LindaNameCheck),
 * and only then the model, which sees the question and the earlier turns'
 * topics, never their text (LindaModelRequest). withoutLookups() is kept for
 * callers that still hold earlier questions with their text.
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
