<?php

namespace Tests\Feature\Accounting\Statements;

use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\Loan;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\JournalPoster;
use App\Services\Accounting\LoanReceivableAccounts;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Decision 9: loans are operating activities, a release is one net line, and
 * what was withheld at release is listed as non-cash, outside every total.
 */
class CashFlowLoansOperatingTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function report(): array
    {
        $from = now()->subDays(40)->toDateString();
        $to = now()->addDays(40)->toDateString();

        return $this->getJson("/api/accounting/statements/cash-flow?from={$from}&to={$to}")->assertOk()->json('data');
    }

    private function lineOfKind(array $report, string $kind): ?array
    {
        return collect($report['operating']['lines'])->firstWhere('kind', $kind);
    }

    private function releaseJournal(Loan $loan): AccountingJournal
    {
        return AccountingJournal::query()
            ->where('postable_type', $loan->getMorphClass())
            ->where('postable_id', $loan->id)
            ->where('source', 'loan_release')
            ->firstOrFail();
    }

    /** credit - debit per account code on one journal, money and receivable lines left out. */
    private function withheld(AccountingJournal $journal): array
    {
        $skip = [...array_map('intval', $this->cashIds()), ...LoanReceivableAccounts::ids()];

        return $journal->lines()->with('account')->get()
            ->reject(fn ($line): bool => in_array((int) $line->accounting_account_id, $skip, true))
            ->groupBy(fn ($line): string => $line->account->code)
            ->map(fn ($lines): int => (int) $lines->sum('credit') - (int) $lines->sum('debit'))
            ->sortKeys()
            ->all();
    }

    /** @return list<int> */
    private function cashIds(): array
    {
        return DB::table('accounting_accounts')->whereNotNull('cash_kind')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function cashOn(AccountingJournal $journal, string $code): int
    {
        $lines = fn () => $journal->lines()->where('accounting_account_id', $this->account($code));

        return (int) $lines()->sum('debit') - (int) $lines()->sum('credit');
    }

    private function assertReconciled(array $report): void
    {
        $sections = $report['operating']['total'] + $report['investing']['total'] + $report['financing']['total'];
        $this->assertSame($report['net_change'], $sections);
        $this->assertTrue($report['is_reconciled']);
        $this->assertSame(0, $report['unexplained']);
    }

    public function test_a_release_is_one_net_operating_line_and_its_deductions_are_non_cash(): void
    {
        $this->seedChartOfAccounts();
        $loan = $this->createReleasedLoan();
        $journal = $this->releaseJournal($loan);
        $net = $this->cashOn($journal, '1010');
        $withheld = $this->withheld($journal);
        $this->assertLessThan(0, $net);
        $this->assertNotEmpty($withheld, 'the fixture loan has deductions');

        $report = $this->report();

        $this->assertSame([
            'account_id' => null,
            'account_code' => null,
            'label' => 'Loans released, net of amounts withheld',
            'amount' => $net,
            'kind' => 'loan_releases',
        ], $this->lineOfKind($report, 'loan_releases'));
        $this->assertSame(-$net + array_sum($withheld), (int) round(((float) $loan->principal_amount) * 100));
        $this->assertSame([], $report['investing']['lines']);
        $this->assertSame([], collect($report['operating']['lines'])->where('kind', 'account')->values()->all(), 'no per-account line from a release');

        $this->assertSame('Withheld at release (non-cash)', $report['non_cash']['label']);
        $this->assertSame($withheld, collect($report['non_cash']['lines'])->pluck('amount', 'account_code')->sortKeys()->all());
        $this->assertSame(array_sum($withheld), $report['non_cash']['total']);
        $this->assertSame(['account_id', 'account_code', 'label', 'amount', 'kind'], array_keys($report['non_cash']['lines'][0]));
        $this->assertSame(['account'], collect($report['non_cash']['lines'])->pluck('kind')->unique()->values()->all());

        $this->assertSame($net, $report['operating']['total'], 'non_cash is outside every section total');
        $this->assertReconciled($report);
    }

    public function test_a_collections_principal_reads_operating(): void
    {
        $this->seedChartOfAccounts();
        $loan = $this->createReleasedLoan();

        $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => 11800,
            'method' => 'cash',
        ])->assertCreated();

        $report = $this->report();
        $operating = collect($report['operating']['lines'])->where('kind', 'account')->pluck('amount', 'account_code');

        $this->assertGreaterThan(0, $operating->get('1110'));
        $this->assertGreaterThan(0, $operating->get('4010'));
        $this->assertSame([], $report['investing']['lines']);
        $this->assertSame('account', collect($report['operating']['lines'])->firstWhere('account_code', '1110')['kind']);
        $this->assertReconciled($report);
    }

    public function test_a_restructure_release_shows_the_deductions_it_collected(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->createReleasedLoan();
        $new->update([
            'source_loan_id' => $source->id,
            'restructure_carried_principal' => '50000.00',
            'restructure_capitalised_interest' => '7000.00',
            'restructure_capitalised_penalty' => '3000.00',
            'restructure_written_off_principal' => '0.00',
        ]);
        $this->seedChartOfAccounts();

        $journal = DB::transaction(fn () => app(AutomaticPoster::class)
            ->restructureRelease($new->fresh(), $source, $this->admin->id, 'cash')['release']);
        $collected = $this->cashOn($journal, '1010');
        $this->assertGreaterThan(0, $collected);

        $report = $this->report();

        $this->assertSame([
            'account_id' => null,
            'account_code' => null,
            'label' => 'Deductions collected on restructures',
            'amount' => $collected,
            'kind' => 'restructure_collections',
        ], $this->lineOfKind($report, 'restructure_collections'));
        $this->assertNull($this->lineOfKind($report, 'loan_releases'));
        $this->assertSame([], $report['non_cash']['lines'], 'a restructure collects its deductions in cash');
        $this->assertSame(0, $report['non_cash']['total']);
        $this->assertSame([], collect($report['operating']['lines'])->where('kind', 'account')->values()->all());
        $this->assertReconciled($report);
    }

    public function test_a_reversed_release_nets_out_of_the_line_and_the_non_cash_block(): void
    {
        $this->seedChartOfAccounts();
        $journal = $this->releaseJournal($this->createReleasedLoan());

        app(JournalPoster::class)->reverse($journal, now()->toDateString(), 'Released in error', $this->admin->id);

        $report = $this->report();

        $this->assertNull($this->lineOfKind($report, 'loan_releases'));
        $this->assertSame([], $report['non_cash']['lines']);
        $this->assertSame(0, $report['non_cash']['total']);
        $this->assertSame([], $report['operating']['lines']);
        $this->assertReconciled($report);
    }

    public function test_every_account_line_says_kind_account_and_non_cash_is_always_present(): void
    {
        $this->seedChartOfAccounts();
        $this->postSimpleJournal('1010', '3010', 1000000, ['date' => now()->toDateString()]);
        $this->postSimpleJournal('1410', '1010', 200000, ['date' => now()->toDateString()]);

        $report = $this->report();

        foreach (['operating', 'investing', 'financing'] as $section) {
            foreach ($report[$section]['lines'] as $line) {
                $this->assertSame('account', $line['kind']);
            }
        }
        $this->assertSame(-200000, $report['investing']['total']);
        $this->assertSame(['label' => 'Withheld at release (non-cash)', 'lines' => [], 'total' => 0], $report['non_cash']);
        $this->assertReconciled($report);
    }

    public function test_the_receivable_accounts_are_the_mapped_account_and_its_siblings_under_a_heading(): void
    {
        $this->assertSame([], LoanReceivableAccounts::ids(), 'no chart, no accounts');

        $this->seedChartOfAccounts();
        $this->assertEqualsCanonicalizing([$this->account('1110'), $this->account('1120')], LoanReceivableAccounts::ids());

        // Mapped to an account with no heading above it: that account alone.
        $alone = AccountingAccount::create(['code' => '1190', 'name' => 'Microloans', 'type' => 'asset']);
        DB::table('accounting_account_mappings')->where('role', 'loans_receivable')->update(['accounting_account_id' => $alone->id]);
        $this->assertSame([$alone->id], LoanReceivableAccounts::ids());
    }
}
