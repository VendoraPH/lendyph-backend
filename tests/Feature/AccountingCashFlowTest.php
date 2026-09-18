<?php

namespace Tests\Feature;

use App\Models\AccountingAccount;
use App\Services\Accounting\CashFlowCategories;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * The cash flow classification, and the statement built on it.
 *
 * The user asked to review these defaults, and the design answer to that is not
 * a settings screen nobody opens — it is that the statement prints ONE LINE PER
 * ACCOUNT under the heading it actually used. So several tests here assert that
 * "1110 Loans Receivable" is visible, by code and by name, under Investing
 * activities. A classification folded into a subtotal is one nobody can
 * disagree with, and a wrong one would look exactly like a right one.
 */
class AccountingCashFlowTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    private function categoryOf(string $code): ?string
    {
        return AccountingAccount::where('code', $code)->value('cash_flow_category');
    }

    /** @return array<string, mixed> */
    private function report(string $from = '2026-09-01', string $to = '2026-09-30'): array
    {
        return $this->getJson("/api/accounting/statements/cash-flow?from={$from}&to={$to}")
            ->assertOk()->json('data');
    }

    /** `{code} => amount` for one section of the report. */
    private function sectionLines(array $report, string $section): array
    {
        return collect($report[$section]['lines'])
            ->mapWithKeys(fn (array $l): array => [$l['account_code'] => $l['amount']])
            ->all();
    }

    // ── The seeded defaults ──

    public function test_the_defaults_follow_the_stated_rule(): void
    {
        // Loans receivable — an asset.
        $this->assertSame('investing', $this->categoryOf('1110'));
        $this->assertSame('investing', $this->categoryOf('1410'));

        // Share capital and members' money — equity and liabilities.
        $this->assertSame('financing', $this->categoryOf('3010'));
        $this->assertSame('financing', $this->categoryOf('2100'));

        // What the business does.
        $this->assertSame('operating', $this->categoryOf('4010'));
        $this->assertSame('operating', $this->categoryOf('5030'));

        // Cash is not an activity — it is what the statement explains.
        foreach (['1010', '1020', '1030', '1040'] as $code) {
            $this->assertSame('cash', $this->categoryOf($code));
        }
    }

    /**
     * THERE ARE NO EXCEPTIONS. Every account matches the rule, with no
     * qualifications.
     *
     * This is the test that would have caught the bug this design started with.
     * An earlier draft special-cased Accounts Payable to `operating` in the
     * migration's backfill — defensible accounting, and it only worked on boxes
     * that were already migrated. A chart seeded AFTERWARDS got its
     * classification from the model's saving hook, which knows the type and
     * nothing else, so the same account was `operating` on one deployment and
     * `financing` on another, from identical code.
     *
     * Asserting the rule over the whole chart, rather than spot-checking a
     * handful of codes, is what makes a reintroduced exception fail here
     * instead of in a cash flow statement six months from now.
     */
    public function test_no_account_in_the_chart_departs_from_the_rule(): void
    {
        $departures = AccountingAccount::query()
            ->get(['code', 'name', 'type', 'cash_kind', 'cash_flow_category'])
            ->reject(fn (AccountingAccount $a): bool => $a->cash_flow_category
                === CashFlowCategories::defaultFor($a->type, $a->cash_kind))
            ->map(fn (AccountingAccount $a): string => "{$a->code} {$a->name} is {$a->cash_flow_category}")
            ->all();

        $this->assertSame([], array_values($departures));

        // And the consequence worth naming out loud: Accounts Payable is
        // `financing`, which is the first classification an accountant should
        // look at. Settling a trade payable is arguably operating. The report
        // prints it by code and name under that heading precisely so the
        // argument can be had — and `PUT /accounting/accounts/{id}` settles it.
        $this->assertSame('financing', $this->categoryOf('2010'));
    }

    public function test_a_new_account_is_classified_rather_than_left_blank(): void
    {
        // An account with no classification would drop off the statement while
        // its amount stayed inside the net change — a difference with nothing
        // naming the account responsible.
        $account = $this->postJson('/api/accounting/accounts', [
            'code' => '5199', 'name' => 'Staff Training', 'type' => 'expense',
        ])->assertCreated()->json('data');

        $this->assertSame('operating', $account['cash_flow_category']);
    }

    // ── Editable ──

    public function test_a_classification_can_be_changed_and_the_report_follows(): void
    {
        // Releasing a loan: cash out, receivable up.
        $this->postSimpleJournal('1110', '1010', 5000000, ['date' => '2026-09-10']);

        $before = $this->report();
        $this->assertArrayHasKey('1110', $this->sectionLines($before, 'investing'));

        // An accountant decides that for a lender, lending IS the operation.
        $this->putJson('/api/accounting/accounts/'.$this->account('1110'), [
            'cash_flow_category' => 'operating',
        ])->assertOk()->assertJsonPath('data.cash_flow_category', 'operating');

        $after = $this->report();

        $this->assertArrayHasKey('1110', $this->sectionLines($after, 'operating'));
        $this->assertArrayNotHasKey('1110', $this->sectionLines($after, 'investing'));
    }

    public function test_an_unknown_classification_is_refused(): void
    {
        $this->putJson('/api/accounting/accounts/'.$this->account('1110'), [
            'cash_flow_category' => 'somewhere_else',
        ])->assertStatus(422)->assertJsonValidationErrors('cash_flow_category');
    }

    // ── The statement ──

    /**
     * Every account is named, with its code, under the heading it was filed in.
     *
     * This IS the review mechanism. "Investing activities ₱(50,000)" says
     * nothing about whether the classification was right; "1110 Loans
     * Receivable ₱(50,000)" under the same heading lets someone see at a glance
     * that loan releases are being treated as investing.
     */
    public function test_every_line_names_its_account_so_the_classification_is_visible(): void
    {
        $this->postSimpleJournal('1110', '1010', 5000000, ['date' => '2026-09-10']);

        $line = $this->report()['investing']['lines'][0];

        $this->assertSame('1110', $line['account_code']);
        $this->assertSame('Current Loans Receivable', $line['label']);
        $this->assertSame($this->account('1110'), $line['account_id']);
        $this->assertSame(-5000000, $line['amount']);
    }

    public function test_the_three_sections_are_always_present_even_when_empty(): void
    {
        $report = $this->report();

        foreach (['operating', 'investing', 'financing'] as $section) {
            $this->assertArrayHasKey($section, $report);
            $this->assertSame([], $report[$section]['lines']);
            $this->assertSame(0, $report[$section]['total']);
        }

        // An absent heading looks like a bug; "no financing activity this
        // period" is information.
        $this->assertSame('Financing activities', $report['financing']['label']);
    }

    /** Money in is positive, money out is negative — in integer centavos. */
    public function test_amounts_are_signed_integer_centavos(): void
    {
        // ₱15,000.50 of interest collected in cash.
        $this->postSimpleJournal('1010', '4010', 1500050, ['date' => '2026-09-12']);

        $report = $this->report();

        $this->assertSame(1500050, $this->sectionLines($report, 'operating')['4010']);
        $this->assertIsInt($report['operating']['total']);
        $this->assertSame(1500050, $report['net_change']);
    }

    public function test_opening_cash_is_the_close_of_the_day_before(): void
    {
        $this->postSimpleJournal('1010', '3010', 3000000, ['date' => '2026-08-31']);
        $this->postSimpleJournal('1010', '4010', 1000000, ['date' => '2026-09-05']);

        $report = $this->report();

        // August's deposit is the opening balance, not a September flow. Using
        // the first day of the period as the cut-off would fold that day's own
        // movements into the opening figure and take them off the statement.
        $this->assertSame(3000000, $report['opening_cash']);
        $this->assertSame(4000000, $report['closing_cash']);
        $this->assertSame(1000000, $report['net_change']);
    }

    /**
     * The statement proves itself: the three sections add up to the movement in
     * cash, computed independently from the cash accounts' own balances.
     */
    public function test_the_sections_account_for_the_whole_change_in_cash(): void
    {
        $this->postSimpleJournal('1010', '3010', 10000000, ['date' => '2026-09-01']); // financing in
        $this->postSimpleJournal('1110', '1010', 5000000, ['date' => '2026-09-05']);  // investing out
        $this->postSimpleJournal('1010', '4010', 1500050, ['date' => '2026-09-12']);  // operating in
        $this->postSimpleJournal('5030', '1010', 250000, ['date' => '2026-09-15']);   // operating out

        $report = $this->report();

        $sections = $report['operating']['total'] + $report['investing']['total'] + $report['financing']['total'];

        $this->assertSame($report['net_change'], $sections);
        $this->assertSame(0, $report['unexplained']);
        $this->assertTrue($report['is_reconciled']);

        $this->assertSame(1250050, $report['operating']['total']);
        $this->assertSame(-5000000, $report['investing']['total']);
        $this->assertSame(10000000, $report['financing']['total']);
    }

    /**
     * A sweep between two of the organisation's own accounts contributes
     * nothing to any section.
     *
     * It falls out of the method rather than being special-cased, which is why
     * `cash` exists as a category at all: the entry has no non-cash line, so
     * there is nothing to classify.
     */
    public function test_moving_money_between_two_own_accounts_is_not_a_flow(): void
    {
        $this->postSimpleJournal('1010', '3010', 10000000, ['date' => '2026-09-01']);

        $this->postJson('/api/accounting/cash-accounts/transfer', [
            'date' => '2026-09-10',
            'from_account_id' => $this->account('1010'),
            'to_account_id' => $this->account('1040'),
            'amount' => 4000000,
            'description' => 'Cash to bank',
        ])->assertCreated();

        $report = $this->report();

        $this->assertSame(10000000, $report['net_change']);
        $this->assertSame(10000000, $report['financing']['total']);
        $this->assertSame(0, $report['operating']['total']);
        $this->assertSame(0, $report['investing']['total']);
        $this->assertSame(0, $report['unexplained']);
    }

    public function test_an_entry_that_never_touches_cash_is_not_on_the_statement(): void
    {
        $this->postSimpleJournal('1010', '3010', 1000000, ['date' => '2026-09-01']);
        // An accrual: cost recognised, nothing paid.
        $this->postSimpleJournal('5030', '2010', 750000, ['date' => '2026-09-10']);

        $report = $this->report();

        $this->assertSame([], $this->sectionLines($report, 'operating'));
        $this->assertSame(0, $report['unexplained']);
    }

    public function test_a_report_endpoint_needs_a_range_and_refuses_a_backwards_one(): void
    {
        $this->getJson('/api/accounting/statements/cash-flow')
            ->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);

        $this->getJson('/api/accounting/statements/cash-flow?from=2026-09-30&to=2026-09-01')
            ->assertStatus(422)->assertJsonValidationErrors('to');
    }

    public function test_it_needs_accounting_view(): void
    {
        $this->actingAs($this->userWithNoRole())
            ->getJson('/api/accounting/statements/cash-flow?from=2026-09-01&to=2026-09-30')
            ->assertForbidden();
    }
}
