<?php

namespace App\Services\Linda;

use App\Models\User;

/**
 * What a follow-up keeps from the conversation (money review M1), one rule
 * for LindaAssistant and `linda:evaluate`.
 *
 * Only a follow-up keeps anything. It walks back through the earlier
 * questions, newest first, for as long as they were follow-ups themselves,
 * taking a period or branch a follow-up named, and stops at the first
 * question that wasn't one: the question the chain follows. That question's
 * branch is kept, none included, and its period too when it is on the same
 * subject (its this-month default when it named none and the subject has
 * one). An older question never fills in what a newer one left out.
 *
 * Each earlier question is read again by the matcher with the intent of the
 * turn before it; one the matcher can't read ends the walk, keeping the
 * period its words name. The period search also stops at the first earlier
 * question on another subject, so a period never skips a change of subject.
 *
 * With the asking user, a branch is the user's own: the matcher's branch name
 * when it is one of the user's branches, else one of the user's branch names
 * found in the question's words (a question only the model understood, or a
 * "sa ..." phrase the matcher read too much into), else the matcher's name as
 * read, which answers "not among your branches". A branch the user can't see
 * is never looked for.
 *
 * A question with no period whose intent defaults to this month then gets
 * this month, and an intent that takes no period has none.
 */
final class LindaFollowUp
{
    /**
     * @param  list<array{question: string, intent: ?string}>  $earlier  oldest first
     * @return array{0: ?LindaPeriod, 1: ?string}
     */
    public static function carry(LindaIntent $intent, ?LindaPeriod $period, ?string $branchName, bool $followUp, #[\SensitiveParameter] array $earlier, ?User $user = null): array
    {
        if ($followUp) {
            $earlier = array_values($earlier);
            $periodOpen = $period === null;
            $branchOpen = $branchName === null;

            for ($i = count($earlier) - 1; $i >= 0 && ($periodOpen || $branchOpen); $i--) {
                $entry = $earlier[$i];
                $sameSubject = $entry['intent'] === $intent->value;
                $before = $i > 0 ? LindaIntent::tryFrom((string) $earlier[$i - 1]['intent']) : null;
                $match = LindaMatcher::match($entry['question'], $before);

                // A change of subject ends the period search: an older
                // question's period never skips over it.
                if (! $sameSubject) {
                    $periodOpen = false;
                }

                if ($branchOpen && ($found = self::branchOf($match, $entry['question'], $user)) !== null) {
                    $branchName = $found;
                    $branchOpen = false;
                }

                if ($match === null) {
                    if ($periodOpen) {
                        $period = LindaPeriod::extract(LindaText::clean($entry['question']))['period'];
                    }

                    break;
                }

                if ($periodOpen && $match->period !== null) {
                    $period = $match->period;
                    $periodOpen = false;
                }

                if (! $match->followUp) {
                    break;
                }
            }
        }

        if (! $intent->takesPeriod()) {
            return [null, $branchName];
        }

        if ($period === null && $intent->defaultsToThisMonth()) {
            $period = LindaPeriod::named('this_month');
        }

        return [$period, $branchName];
    }

    /** The branch an earlier question names, by the rule in the class comment. */
    private static function branchOf(?LindaMatch $match, #[\SensitiveParameter] string $question, ?User $user): ?string
    {
        $named = $match?->branchName;

        if ($user === null) {
            return $named;
        }

        if ($named !== null && LindaScope::resolve($user, $named)->ok()) {
            return $named;
        }

        return LindaScope::visibleBranchIn($user, $question) ?? $named;
    }
}
