<?php

namespace Tests\Unit;

use App\Services\Accounting\Money;
use Tests\TestCase;

/**
 * The port of `src/lib/accounting/journal.test.ts` — the half of it that is
 * pure on this side of the wire.
 *
 * `buildJournalPayload` has no server-side counterpart by design: the frontend
 * converts raw input text to integer centavos BEFORE sending, and the API's
 * contract is that a line's `debit`/`credit` are already integers. What the
 * backend owns is the arithmetic those integers are produced by and checked
 * against, which is {@see Money} — the PHP half of `@/lib/accounting/money`,
 * and the reason the two sides can agree on a centavo at all.
 *
 * The behavioural half of `journal.test.ts` — at least two lines, exactly one
 * side per line, an entry must balance, a draft cannot be reversed, a reversal
 * mirrors every line — is exercised end to end against the real endpoints in
 * `AccountingJournalPostingTest` and `AccountingJournalReversalTest`, because
 * on this side those rules are enforced by a FormRequest, a service and a CHECK
 * constraint rather than by one function.
 */
class AccountingJournalRulesTest extends TestCase
{
    // ── Raw peso text to integer centavos ──

    public function test_raw_peso_text_becomes_integer_centavos(): void
    {
        $this->assertSame(150050, Money::toCentavos('1,500.50'));
        $this->assertSame(150050, Money::toCentavos('1500.50'));
    }

    public function test_grouped_and_ungrouped_spellings_are_indistinguishable(): void
    {
        // The heart of it: PHP casts "1,500.50" to 1.0 and "1500.50" to 1500.5,
        // so an entry the form called "Balanced, 150050 both sides" reached the
        // ledger as ₱1.00 against ₱1,500.50. Parsed, never cast.
        $this->assertSame(Money::toCentavos('1,500.50'), Money::toCentavos('1500.50'));
    }

    public function test_a_peso_sign_and_thousands_separators_are_resolved(): void
    {
        $this->assertSame(123456789, Money::toCentavos(' ₱ 1 234 567.89 '));
        $this->assertSame(Money::toCentavos(' ₱ 1 234 567.89 '), Money::toCentavos('1234567.89'));
    }

    public function test_a_rounding_boundary_lands_on_the_same_centavo_as_the_frontend(): void
    {
        // 1.555 * 100 is 155.49999999999997 in IEEE-754. The frontend's
        // roundHalfUp nudges it back onto the boundary and so does this; a
        // half-centavo of disagreement between the two is an entry that
        // balances on screen and not in the books.
        $this->assertSame(156, Money::toCentavos('1.555'));
        $this->assertSame(156, Money::toCentavos(1.555));
    }

    public function test_a_negative_amount_is_refused_rather_than_flipped(): void
    {
        // Direction belongs to the column, not the sign. A "-100" debit is a
        // 100 credit, and accepting both spellings would let two visually
        // different entries post the same numbers.
        $this->assertNull(Money::toCentavos('-100'));
        $this->assertNull(Money::toCentavos(-100));
        $this->assertNull(Money::toCentavos(-100.5));
    }

    public function test_text_where_an_amount_belongs_is_refused(): void
    {
        $this->assertNull(Money::toCentavos('one thousand'));
        $this->assertNull(Money::toCentavos('1.2.3'));
        $this->assertNull(Money::toCentavos('abc'));
    }

    public function test_blank_is_null_rather_than_zero(): void
    {
        // In a journal line an empty debit means "this side is unused", while
        // ₱0.00 means "this side is zero", and the entry form has to tell them
        // apart.
        $this->assertNull(Money::toCentavos(''));
        $this->assertNull(Money::toCentavos('   '));
        $this->assertNull(Money::toCentavos(null));
        $this->assertSame(0, Money::toCentavos('0'));
    }

    // ── The ceiling, on every branch ──

    public function test_an_absurd_amount_is_refused_however_it_is_spelled(): void
    {
        // The ceiling used to guard the string path alone — the one path a JSON
        // payload never takes. `json_decode` hands a controller an int or a
        // float, and a number past 2^53 is already approximate by the time PHP
        // sees it, so `* 100` produced a plausible integer that silently
        // differed from what was sent.
        $tooBig = 1_000_000_000_000_000;

        $this->assertNull(Money::toCentavos((string) $tooBig));
        $this->assertNull(Money::toCentavos($tooBig));
        $this->assertNull(Money::toCentavos((float) $tooBig));

        // And the largest legitimate amount still goes through, on all three.
        $largest = 999_999_999_999_999;
        $this->assertSame($largest * 100, Money::toCentavos($largest));
        $this->assertSame($largest * 100, Money::toCentavos((string) $largest));
    }

    public function test_an_infinite_or_nan_float_is_refused(): void
    {
        $this->assertNull(Money::toCentavos(INF));
        $this->assertNull(Money::toCentavos(NAN));
    }

    // ── Totalling the two sides ──

    public function test_totals_are_exact_over_many_lines(): void
    {
        // The loan collection shape: one debit against three credits. Summed as
        // pesos in IEEE-754 this drifts; as integer centavos it cannot.
        $debits = [500000];
        $credits = [400000, 90000, 10000];

        $this->assertSame(500000, Money::sum($debits));
        $this->assertSame(500000, Money::sum($credits));
        $this->assertTrue(Money::isBalanced(Money::sum($debits), Money::sum($credits)));
    }

    public function test_an_out_of_balance_pair_is_not_balanced(): void
    {
        // Exact equality, on purpose — there is no tolerance band in
        // double-entry bookkeeping and integers make none necessary.
        $this->assertFalse(Money::isBalanced(350000, 300000));
        $this->assertFalse(Money::isBalanced(100, 99));
    }

    public function test_a_decimal_string_off_the_wire_still_totals(): void
    {
        // A `decimal:2` cast serialises to JSON as a string, and every centavo
        // field is typed as a number on the strength of a contract nothing
        // checks at the boundary.
        $this->assertSame(1500, Money::sum(['500', 1000]));
    }

    public function test_an_unparseable_value_counts_as_nothing_rather_than_poisoning_the_total(): void
    {
        $this->assertSame(350000, Money::sum(['abc', 350000, null]));
    }

    public function test_formatting_is_pesos_with_the_sign_outside_the_symbol(): void
    {
        $this->assertSame('₱1,500.50', Money::format(150050));
        $this->assertSame('-₱500.00', Money::format(-50000));
        $this->assertSame('₱0.00', Money::format(0));
    }
}
