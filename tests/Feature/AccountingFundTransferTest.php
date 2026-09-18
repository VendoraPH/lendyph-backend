<?php

namespace Tests\Feature;

use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Moving the organisation's own money between its own accounts.
 *
 * The first test here is the one that matters most, and it asserts a NEGATIVE:
 * a transfer touches no income account. Recording a sweep as revenue is the
 * single most damaging mistake a naive lending system makes — it inflates the
 * income statement by the whole amount moved, every time money is moved, and
 * the books still balance perfectly while it happens.
 */
class AccountingFundTransferTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();

        // Money to move.
        $this->postSimpleJournal('1020', '3010', 10000000, ['date' => '2026-09-01']);
    }

    /** @param  array<string, mixed>  $overrides */
    private function transfer(array $overrides = []): array
    {
        return $this->postJson('/api/accounting/cash-accounts/transfer', array_merge([
            'date' => '2026-09-18',
            'from_account_id' => $this->account('1020'),
            'to_account_id' => $this->account('1040'),
            // ₱10,000.50 in centavos.
            'amount' => 1000050,
            'description' => 'GCash to bank sweep',
        ], $overrides))->assertCreated()->json('data');
    }

    /** debit/credit by account code for a journal. */
    private function linesOf(int $journalId): array
    {
        return AccountingJournal::findOrFail($journalId)
            ->lines()->with('account:id,code')->get()
            ->mapWithKeys(fn ($l): array => [
                $l->account->code => ['debit' => (int) $l->debit, 'credit' => (int) $l->credit],
            ])->all();
    }

    // ── The shape the client expects ──

    /**
     * `accountingService.transfer` is typed to return a `JournalEntry`, and the
     * Cash & Bank screen may render it. An endpoint that answered with anything
     * else would look finished and be unusable.
     */
    public function test_it_answers_with_a_journal_entry(): void
    {
        $journal = $this->transfer();

        $this->assertSame('transfer', $journal['source']);
        $this->assertSame('posted', $journal['status']);
        $this->assertStringStartsWith('JE-', $journal['journal_no']);
        $this->assertSame('2026-09-18', $journal['date']);

        // Lines carry denormalised codes and names so a ledger table need not
        // join client-side — the same shape the Journals screen consumes.
        $this->assertCount(2, $journal['lines']);
        $this->assertArrayHasKey('account_code', $journal['lines'][0]);
        $this->assertArrayHasKey('account_name', $journal['lines'][0]);

        // CENTAVOS, as integers.
        $this->assertSame(1000050, $journal['total_debit']);
        $this->assertSame(1000050, $journal['total_credit']);
        $this->assertIsInt($journal['total_debit']);
    }

    // ── The accounting ──

    public function test_it_debits_the_destination_and_credits_the_source(): void
    {
        $journal = $this->transfer();

        $this->assertSame([
            '1040' => ['debit' => 1000050, 'credit' => 0],
            '1020' => ['debit' => 0, 'credit' => 1000050],
        ], $this->linesOf($journal['id']));
    }

    /**
     * THE ONE THAT MATTERS.
     *
     * Not a single income account moves. A ₱200,000 weekly sweep booked as
     * revenue is ₱10.4M of income a year that nobody earned, reported on a set
     * of books that balance.
     */
    public function test_a_transfer_is_never_income(): void
    {
        $this->transfer();

        $income = AccountingAccount::query()->where('type', 'income')->pluck('id');

        $this->assertSame(
            0,
            AccountingJournal::query()
                ->whereHas('lines', fn ($q) => $q->whereIn('accounting_account_id', $income))
                ->where('source', 'transfer')
                ->count(),
            'A fund transfer touched an income account.',
        );

        // And the books are no richer: one asset down, another up, by the same
        // amount.
        $gcash = $this->getJson('/api/accounting/accounts/'.$this->account('1020'))->json('data.balance');
        $bank = $this->getJson('/api/accounting/accounts/'.$this->account('1040'))->json('data.balance');

        $this->assertSame(10000000 - 1000050, $gcash);
        $this->assertSame(1000050, $bank);
    }

    /**
     * A charge is an expense, and the SOURCE bears it.
     *
     * `amount` is what arrives at the destination; the charge is taken on top,
     * out of the account the money left. Netting it out of what arrives would
     * make the destination's balance disagree with the figure the user typed.
     */
    public function test_a_charge_is_an_expense_taken_out_of_the_source(): void
    {
        $journal = $this->transfer(['charge' => 1500]);

        $this->assertSame([
            '1040' => ['debit' => 1000050, 'credit' => 0],
            // 5080 GCash Charges — resolved from the SOURCE account's cash kind.
            '5080' => ['debit' => 1500, 'credit' => 0],
            '1020' => ['debit' => 0, 'credit' => 1001550],
        ], $this->linesOf($journal['id']));
    }

    public function test_a_charge_can_be_pointed_at_an_explicit_account(): void
    {
        $journal = $this->transfer([
            'charge' => 1500,
            'charge_account_id' => $this->account('5070'),
        ]);

        $lines = $this->linesOf($journal['id']);
        $this->assertSame(['debit' => 1500, 'credit' => 0], $lines['5070']);
        $this->assertArrayNotHasKey('5080', $lines);
    }

    /**
     * No account for the charge means NOTHING is recorded.
     *
     * Failing closed, like every other posting path here. A charge quietly
     * dropped from the entry would leave the source credited for an amount the
     * journal did not account for — which cannot balance — or, worse, would
     * shrink the credit so it did balance, and the money would simply never
     * have left.
     */
    public function test_a_charge_with_nowhere_to_go_refuses_the_whole_transfer(): void
    {
        AccountingAccount::query()->whereIn('code', ['5070', '5080'])->update(['is_active' => false]);

        $before = AccountingJournal::count();

        $this->postJson('/api/accounting/cash-accounts/transfer', [
            'date' => '2026-09-18',
            'from_account_id' => $this->account('1020'),
            'to_account_id' => $this->account('1040'),
            'amount' => 1000050,
            'charge' => 1500,
            'description' => 'GCash to bank sweep',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('charge_account_id');

        $this->assertSame($before, AccountingJournal::count());
    }

    public function test_a_zero_charge_adds_no_line(): void
    {
        $journal = $this->transfer(['charge' => 0]);

        $this->assertCount(2, $journal['lines']);
    }

    // ── Refusals ──

    public function test_the_same_account_on_both_sides_is_refused(): void
    {
        // Two lines against one account net to nothing and read as activity
        // that never happened.
        $this->postJson('/api/accounting/cash-accounts/transfer', [
            'date' => '2026-09-18',
            'from_account_id' => $this->account('1020'),
            'to_account_id' => $this->account('1020'),
            'amount' => 1000050,
            'description' => 'Nowhere',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('to_account_id');
    }

    public function test_money_can_only_move_between_money_accounts(): void
    {
        // 1110 Loans Receivable is an asset but not money. Crediting it here
        // would report the loan portfolio as having been spent.
        $this->postJson('/api/accounting/cash-accounts/transfer', [
            'date' => '2026-09-18',
            'from_account_id' => $this->account('1020'),
            'to_account_id' => $this->account('1110'),
            'amount' => 1000050,
            'description' => 'Wrong',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('to_account_id');
    }

    public function test_a_decimal_amount_is_refused_rather_than_floored(): void
    {
        // 10000.50 floored to 10000 would move a hundredth of the intended
        // amount and leave two real balances wrong in opposite directions.
        $this->postJson('/api/accounting/cash-accounts/transfer', [
            'date' => '2026-09-18',
            'from_account_id' => $this->account('1020'),
            'to_account_id' => $this->account('1040'),
            'amount' => 10000.50,
            'description' => 'Sweep',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }

    public function test_it_needs_the_transfer_permission(): void
    {
        // `general_bookkeeper` holds `cash_accounts:view` and NOT
        // `cash_accounts:transfer` — reading the cash position and moving money
        // are not the same act.
        $this->actingAs($this->userWithRole('general_bookkeeper'))
            ->postJson('/api/accounting/cash-accounts/transfer', [
                'date' => '2026-09-18',
                'from_account_id' => $this->account('1020'),
                'to_account_id' => $this->account('1040'),
                'amount' => 1000050,
                'description' => 'Sweep',
            ])
            ->assertForbidden();
    }
}
