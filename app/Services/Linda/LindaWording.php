<?php

namespace App\Services\Linda;

use App\Services\Accounting\Money;

/**
 * Every sentence Linda writes, in simple English: "Member", never
 * "Borrower"; money as ₱ with two decimals; dates in Manila, read through
 * LindaPeriod's labels. The server writes each answer from these templates,
 * so no figure in an answer is ever the model's.
 */
final class LindaWording
{
    /**
     * The panel's five suggested questions, word for word
     * (lendyph-frontend src/lib/linda.ts LINDA_SUGGESTED_QUESTIONS).
     */
    public const SUGGESTED_QUESTIONS = [
        'How much have we collected this month?',
        'Which members currently have overdue payments?',
        'How many active loans do we have?',
        'How much have we disbursed this month?',
        'Give me a summary of our lending operation this month.',
    ];

    /** The longest part of a typed branch name repeated back in a reply. */
    private const ECHO_MAX = 60;

    /** ₱ with two decimals, half up to the centavo: 1500 → "₱1,500.00". */
    public static function money(int|float|string $pesos): string
    {
        return Money::format(Money::toCentavos(is_string($pesos) ? $pesos : (float) $pesos) ?? 0);
    }

    /** "1 payment", "1,204 payments". */
    public static function count(int $count, string $singular, ?string $plural = null): string
    {
        return number_format($count).' '.($count === 1 ? $singular : ($plural ?? $singular.'s'));
    }

    /** "from November 1 to 16, 2026", or "on November 16, 2026" for one day. */
    public static function during(LindaPeriod $period): string
    {
        return ($period->from->isSameDay($period->to) ? 'on ' : 'from ').$period->label;
    }

    /** Today's Manila date: "November 16, 2026". */
    public static function today(): string
    {
        return LindaPeriod::today()->format('F j, Y');
    }

    /** "As of today, November 16, 2026" */
    public static function asOfToday(): string
    {
        return 'As of today, '.self::today();
    }

    /**
     * The note added when a question about figures that are only kept as of
     * today named a period.
     */
    public static function todayOnly(): string
    {
        return 'These figures are as of today; I can\'t show them for an earlier date yet.';
    }

    /** The five questions, one per line. */
    public static function suggestions(): string
    {
        return implode("\n", array_map(fn (string $question): string => '- '.$question, self::SUGGESTED_QUESTIONS));
    }

    public static function unmatched(): string
    {
        return "I can't answer that question yet. Here's what I can answer for now:\n\n"
            .self::suggestions()
            ."\n\nYou can also name a period (\"last month\") or one of your branches.";
    }

    public static function aiOffForNow(): string
    {
        return "I can't read that question right now. These questions still work:\n\n"
            .self::suggestions();
    }

    public static function unclearPeriod(): string
    {
        return 'I couldn\'t tell which dates you mean. Please name a period, like "this month", "last week" or "November 1 to 15, 2026".';
    }

    /** @param  string  $reason  "future", or "over_10_years" */
    /** @param  string  $reason  LindaPeriod::REFUSED_FUTURE, REFUSED_TOO_LONG or REFUSED_INVALID */
    public static function periodRefused(string $reason): string
    {
        return match ($reason) {
            LindaPeriod::REFUSED_FUTURE => 'That period is in the future, so there are no figures for it yet. Please ask about today or an earlier date.',
            LindaPeriod::REFUSED_INVALID => 'That period doesn\'t work: its start comes after its end. Try something like "October 1 to 15".',
            default => 'That period is longer than 10 years. Please ask about a shorter period.',
        };
    }

    public static function unsupported(): string
    {
        return 'Sorry, I can only answer questions about your cooperative\'s lending figures, like collections, disbursements, active loans and overdue payments.';
    }

    public static function notYet(): string
    {
        return 'I can\'t answer that kind of question yet. For now I can answer questions about collections, disbursements, active loans, overdue payments and a lending summary.';
    }

    public static function notYetWithReport(): string
    {
        return self::notYet().' The report linked below may help.';
    }

    public static function branchNotFound(string $name): string
    {
        return '"'.self::echoed($name).'" is not among your branches. Please check the branch name and ask again.';
    }

    /** @param  list<string>  $names */
    public static function branchAmbiguous(array $names): string
    {
        return 'More than one of your branches fits that name: '.self::listOf($names).'. Which one do you mean?';
    }

    public static function noBranchAssigned(): string
    {
        return 'No branch is assigned to your account, so there are no figures for you to see. Please ask your administrator to assign one.';
    }

    /** @param  string  $what  what the answer shows, e.g. "collection figures" */
    public static function denied(string $what): string
    {
        return "Your role doesn't have access to the {$what}. Please ask your administrator if you need them.";
    }

    /** "A, B and C" */
    public static function listOf(array $items): string
    {
        $items = array_values($items);

        if (count($items) <= 1) {
            return implode('', $items);
        }

        return implode(', ', array_slice($items, 0, -1)).' and '.$items[count($items) - 1];
    }

    /** "for Main Branch", "across all your branches", "across all branches" */
    public static function forScope(LindaScope $scope): string
    {
        return (str_starts_with($scope->label, 'all ') ? 'across ' : 'for ').$scope->label;
    }

    /**
     * A typed name as repeated back: spaces squeezed, control characters
     * dropped, cut to a sensible length.
     */
    private static function echoed(string $name): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{C}]/u', '', $name)));

        return mb_strlen($clean) > self::ECHO_MAX ? rtrim(mb_substr($clean, 0, self::ECHO_MAX)).'…' : $clean;
    }
}
