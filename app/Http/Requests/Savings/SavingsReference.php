<?php

namespace App\Http\Requests\Savings;

use App\Services\Accounting\Money;
use Illuminate\Validation\Rule;

/**
 * Money coming in by bank transfer, GCash or Maya needs the reference of that
 * payment (finish run round 5), as a loan payment or a share capital entry
 * does: a deposit, an opening deposit (staff's, or a member portal request's
 * new account) and a time deposit placement. That reference exists before
 * the money is recorded. A blank one is a 422 in words that name the method
 * and the entry ("Enter the GCash reference for this deposit."), and the
 * screens say the same before sending.
 *
 * Money going out never needs one (optional()): a withdrawal, a member portal
 * payout, and a payout at closing, at a time deposit's maturity or on its
 * pre-termination (`payout_reference`). Their reference exists only once the
 * money is sent, so requiring it when the request is made would make a
 * teller send the money before the checker approves it (above tier 1), or
 * before an AMLA order refuses it. A teller who has one records it, and
 * DuplicateReference checks it like any other.
 *
 * Also exempt: cash (a counter receipt; a cash time deposit takes its TD
 * number), `savings` (a placement paid from the member's own savings, a move
 * between their savings records), an opening deposit of ₱0.00 (nothing is
 * paid), and every writer that never comes through these requests:
 * auto-credit runs, interest, transfers between accounts and reversals.
 *
 * New records only: it is a request rule, so nothing stored is re-checked.
 * DuplicateReference (round 2) then checks the reference given.
 */
final class SavingsReference
{
    /** The methods that need a reference, in the words its message uses. */
    public const METHOD_WORDS = [
        'bank_transfer' => 'bank transfer',
        'gcash' => 'GCash',
        'maya' => 'Maya',
    ];

    public const DEPOSIT = 'this deposit';

    public const OPENING_DEPOSIT = 'the opening deposit';

    public const TIME_DEPOSIT = 'this time deposit';

    /** Whether money moved by `$method` needs its reference. */
    public static function needed(mixed $method): bool
    {
        return is_string($method) && array_key_exists($method, self::METHOD_WORDS);
    }

    /**
     * The rules of money coming in: required when `$method` needs one and
     * money moves (`$paid`), otherwise optional; at most `$max` characters.
     *
     * @return list<mixed>
     */
    public static function rules(mixed $method, int $max, bool $paid = true): array
    {
        return [Rule::requiredIf($paid && self::needed($method)), 'nullable', 'string', "max:{$max}"];
    }

    /**
     * The rules of money going out: optional by every method, at most `$max`
     * characters.
     *
     * @return list<string>
     */
    public static function optional(int $max): array
    {
        return ['nullable', 'string', "max:{$max}"];
    }

    /** `Enter the GCash reference for this deposit.` */
    public static function message(mixed $method, string $entry): string
    {
        $words = self::needed($method) ? self::METHOD_WORDS[$method] : 'payment';

        return "Enter the {$words} reference for {$entry}.";
    }

    /**
     * Whether an opening deposit of `$amount` pays anything: false only for an
     * amount that reads as ₱0.00. One that can't be read counts as paid, so
     * its reference is asked for alongside the amount's own error.
     */
    public static function pays(mixed $amount): bool
    {
        return ! ((is_string($amount) || is_int($amount) || is_float($amount)) && Money::toCentavos($amount) === 0);
    }
}
