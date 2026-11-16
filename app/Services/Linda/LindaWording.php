<?php

namespace App\Services\Linda;

use App\Services\Accounting\Money;
use Carbon\CarbonImmutable;

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

    /** A whole percent, two decimals at most, trailing zeros dropped: 87.5 → "87.5%". */
    public static function percent(int|float $percent): string
    {
        return rtrim(rtrim(number_format((float) $percent, 2, '.', ','), '0'), '.').'%';
    }

    /** A rate as the reports show it, one decimal: 54.35 → "54.4%", 0 → "0.0%". */
    public static function rate(int|float $percent): string
    {
        return number_format((float) $percent, 1, '.', ',').'%';
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
     * The earliest unpaid instalment, in words. A date before today (Manila)
     * reads as still unpaid: "₱250.00 was due on October 16, 2026 and is
     * still unpaid." Today or later reads as the next payment.
     */
    public static function nextDue(int|float|string $amount, string $date): string
    {
        $today = LindaPeriod::today()->toDateString();

        if ($date < $today) {
            return self::money($amount).' was due on '.self::date($date).' and is still unpaid.';
        }

        return 'The next payment, '.self::money($amount).', is due '.($date === $today ? 'today, ' : 'on ').self::date($date).'.';
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
            ."\n\nI can also answer about payments, collection efficiency, and branch and officer performance, "
            .'and look up a member, a loan or a receipt by name or number (BRW-…, LN-…, RCP-…). '
            .'You can also name a period ("last month") or one of your branches.';
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
        return 'Sorry, I can only answer questions about your cooperative\'s lending figures, like collections, payments, disbursements, active loans, overdue payments, members, loans and receipts.';
    }

    public static function notYet(): string
    {
        return 'I can\'t answer that kind of question yet. For now I can answer questions about collections, payments, disbursements, active loans, overdue payments, collection efficiency, branch and officer performance and a lending summary, and look up a member, a loan or a receipt.';
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

    /**
     * The question a lookup asks when it names no member, loan or receipt.
     *
     * @param  'member'|'loan'|'receipt'  $kind
     */
    public static function whichRecord(string $kind): string
    {
        return match ($kind) {
            LindaLookupRequest::LOAN => 'Which loan? Type its loan number (LN-…) or application number (LA-…).',
            LindaLookupRequest::RECEIPT => 'Which receipt? Type its receipt number (RCP-…).',
            default => 'Which member? Type their name or member code (BRW-…).',
        };
    }

    public static function noMemberNamed(string $name): string
    {
        return 'No member named "'.self::echoed($name).'" in your branches.';
    }

    public static function noMemberWithCode(string $code): string
    {
        return 'No member with code '.self::echoed($code).' in your branches.';
    }

    public static function noLoanNumbered(string $number): string
    {
        return 'No loan numbered '.self::echoed($number).' in your branches.';
    }

    public static function noReceiptNumbered(string $number): string
    {
        return 'No receipt numbered '.self::echoed($number).' in your branches.';
    }

    /** For a record number Linda can't look up, by its first part ("JE"). */
    public static function cantLookUpNumber(string $prefix): string
    {
        return 'I can look up members (BRW-…), loans (LN-… or LA-…) and receipts (RCP-…) by their number, but not '
            .self::echoed($prefix).'- numbers yet.';
    }

    /**
     * A loan's status as the loan pages word it (LOAN_STATUS_LABELS in the
     * frontend), from Loan::displayStatus(): released and ongoing read
     * "Current", one past grace "Past Due".
     */
    public static function loanStatus(?string $status): string
    {
        return match ($status) {
            'draft' => 'Draft',
            'for_review' => 'For Approval',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'released', 'ongoing' => 'Current',
            'past_due' => 'Past Due',
            'completed' => 'Completed',
            'defaulted' => 'Defaulted',
            'restructured' => 'Restructured',
            'void' => 'Voided',
            default => ucwords(str_replace('_', ' ', (string) $status)),
        };
    }

    /** A payment method in words, as the reports word it. */
    public static function paymentMethod(?string $method): string
    {
        return match ($method) {
            'cash' => 'Cash',
            'gcash' => 'GCash',
            'maya' => 'Maya',
            'bank_transfer' => 'Bank Transfer',
            'online' => 'Online',
            'auto_pay' => 'Auto-pay',
            null, '' => 'Not recorded',
            default => ucwords(str_replace('_', ' ', $method)),
        };
    }

    /** A `YYYY-MM-DD` date in words: "December 1, 2026". */
    public static function date(string $date): string
    {
        return CarbonImmutable::parse($date, LindaPeriod::timezone())->format('F j, Y');
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
    public static function echoed(string $name): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\p{C}]/u', '', $name)));

        return mb_strlen($clean) > self::ECHO_MAX ? rtrim(mb_substr($clean, 0, self::ECHO_MAX)).'…' : $clean;
    }
}
