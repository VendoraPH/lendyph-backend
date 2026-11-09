<?php

namespace Tests\Feature\Accounting\Allowance;

use App\Models\AccountingAllowancePosting;
use App\Models\AccountingJournal;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Loan;
use App\Services\Accounting\AllowancePoster;
use App\Services\Accounting\PeriodCalendar;
use App\Services\Accounting\TrialBalanceBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * GET /accounting/allowance/preview, POST and GET /accounting/allowance/postings:
 * one posting per branch against that branch's 1200 balance.
 */
class AllowancePostingTest extends TestCase
{
    use AllowanceTestHelpers, PostsJournals, SetupLendyPH;

    private Branch $far;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->grantTheAllowancePermission();
        $this->seedChartOfAccounts();
        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR', 'is_active' => true]);
    }

    /**
     * Main: ₱10,000.00 ten days late (5%, ₱500.00 required). Far: ₱2,000.00
     * one hundred days late (50%, ₱1,000.00 required).
     *
     * @return array{0: Loan, 1: Loan}
     */
    private function twoPastDueLoans(): array
    {
        $main = $this->agedLoan();
        $this->period($main, 10, principal: 10000);

        $far = $this->agedLoan(['branch_id' => $this->far->id]);
        $this->period($far, 100, principal: 2000);

        return [$main, $far];
    }

    /** @return array<string, int> signed balance by account code, in its normal direction */
    private function balances(string ...$codes): array
    {
        $balances = app(TrialBalanceBuilder::class)->signedBalances($this->today());

        return collect($codes)->mapWithKeys(fn (string $code): array => [$code => $balances[$this->account($code)] ?? 0])->all();
    }

    /** @return array<string, mixed> */
    private function balanceSheet(): array
    {
        return $this->getJson('/api/accounting/statements/balance-sheet?as_of='.$this->today())->assertOk()->json('data');
    }

    public function test_the_preview_proposes_the_full_required_amount_on_a_first_run(): void
    {
        $this->twoPastDueLoans();

        $preview = $this->preview();

        $this->assertSame($this->today(), $preview['as_of']);
        $this->assertSame(['bucket', 'label', 'rate_percent'], array_keys($preview['rates'][0]));
        $this->assertSame(
            ['branch', 'buckets', 'principal', 'required', 'current_balance', 'adjustment'],
            array_keys($preview['branches'][0]),
        );
        $this->assertSame(['bucket', 'loan_count', 'principal', 'rate_percent', 'required'], array_keys($preview['branches'][0]['buckets'][0]));

        $main = $this->branchRow($preview, $this->branch);
        $this->assertSame([1_000_000, 50_000, 0, 50_000], [$main['principal'], $main['required'], $main['current_balance'], $main['adjustment']]);
        $far = $this->branchRow($preview, $this->far);
        $this->assertSame([200_000, 100_000, 0, 100_000], [$far['principal'], $far['required'], $far['current_balance'], $far['adjustment']]);

        $this->assertSame(
            ['loan_count' => 2, 'principal' => 1_200_000, 'required' => 150_000, 'current_balance' => 0, 'adjustment' => 150_000],
            $preview['totals'],
        );
        $this->assertSame(0, $preview['unassigned_balance']);
        $this->assertNull($preview['last_posting']);
        $this->assertTrue($preview['can_post']);
        $this->assertSame([], $preview['blocking_reasons']);
    }

    public function test_the_first_run_provides_the_full_required_amount_per_branch(): void
    {
        $this->twoPastDueLoans();
        $assetsBefore = $this->balanceSheet()['total_assets'];

        $response = $this->postTheRun()->assertCreated();
        $data = $response->json('data');

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $data['run_id']);
        $this->assertSame($this->today(), $data['as_of']);
        $this->assertSame(['required' => 150_000, 'previous_balance' => 0, 'adjustment' => 150_000], $data['totals']);
        $this->assertCount(2, $data['postings']);
        $this->assertSame(['id', 'branch', 'required', 'previous_balance', 'adjustment', 'journal'], array_keys($data['postings'][0]));

        foreach ([[$this->branch, 50_000], [$this->far, 100_000]] as [$branch, $amount]) {
            $posting = collect($data['postings'])->firstWhere('branch.id', $branch->id);
            $this->assertSame([$amount, 0, $amount], [$posting['required'], $posting['previous_balance'], $posting['adjustment']]);

            $journal = AccountingJournal::query()->with('lines')->findOrFail($posting['journal']['id']);
            $this->assertSame($journal->journal_no, $posting['journal']['journal_no']);
            $this->assertSame('posted', $journal->status);
            $this->assertSame('credit_loss', $journal->source);
            $this->assertSame($branch->id, $journal->branch_id);
            $this->assertSame($this->today(), $journal->date->toDateString());
            $this->assertSame(AccountingAllowancePosting::class, $journal->postable_type);
            $this->assertSame($posting['id'], $journal->postable_id);
            $this->assertSame($amount, (int) $journal->total_debit);
            $this->assertSame((int) $journal->total_debit, (int) $journal->total_credit);
            $this->assertSame($amount, (int) $journal->lines->firstWhere('accounting_account_id', $this->account('5140'))->debit);
            $this->assertSame($amount, (int) $journal->lines->firstWhere('accounting_account_id', $this->account('1200'))->credit);
        }

        $this->assertSame(['1200' => 150_000, '5140' => 150_000], $this->balances('1200', '5140'));
        $sheet = $this->balanceSheet();
        $this->assertSame(-150_000, collect($sheet['assets'])->firstWhere('account_code', '1200')['amount']);
        $this->assertSame($assetsBefore - 150_000, $sheet['total_assets']);
        $this->assertTrue($sheet['is_balanced']);
        $this->assertSame(150_000, $this->getJson('/api/accounting/loan-accounting/summary?as_of='.$this->today())->json('data.books.allowance'));

        $after = $this->preview();
        $this->assertSame(0, $after['totals']['adjustment']);
        $this->assertSame(150_000, $after['totals']['current_balance']);
        $this->assertSame($this->today(), $after['last_posting']['as_of']);
        $this->assertSame(['id' => $this->admin->id, 'name' => $this->admin->full_name], $after['last_posting']['posted_by']);
        $this->assertNotNull($after['last_posting']['posted_at']);
    }

    public function test_one_audit_row_is_written_per_posting(): void
    {
        $this->twoPastDueLoans();
        $data = $this->postTheRun()->assertCreated()->json('data');

        $rows = AuditLog::query()->where('auditable_type', AccountingAllowancePosting::class)->orderBy('id')->get();

        $this->assertCount(2, $rows);
        $this->assertSame(['allowance_posted'], $rows->pluck('action')->unique()->values()->all());
        $first = collect($data['postings'])->firstWhere('id', $rows[0]->auditable_id);
        // assertEquals: a JSON column keeps its own key order.
        $this->assertEquals([
            'run_id' => $data['run_id'],
            'as_of' => $this->today(),
            'branch_id' => $first['branch']['id'],
            'required' => $first['required'],
            'previous_balance' => $first['previous_balance'],
            'adjustment' => $first['adjustment'],
            'accounting_journal_id' => $first['journal']['id'],
            'journal_no' => $first['journal']['journal_no'],
        ], $rows[0]->new_values);
        $this->assertSame($this->admin->id, $rows[0]->user_id);
    }

    public function test_a_second_run_with_nothing_changed_is_refused(): void
    {
        $this->twoPastDueLoans();
        $this->postTheRun()->assertCreated();

        $preview = $this->preview();
        $this->assertFalse($preview['can_post']);
        $this->assertContains('Nothing to post: every branch\'s allowance already equals what is required.', $preview['blocking_reasons']);

        $this->postJson('/api/accounting/allowance/postings', ['as_of' => $this->today(), 'expected_adjustment' => 0])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Nothing to post: every branch\'s allowance already equals what is required.');

        $this->assertSame(2, AccountingAllowancePosting::count());
        $this->assertSame(2, AccountingJournal::query()->where('source', 'credit_loss')->count());
    }

    public function test_after_a_loan_is_paid_down_a_run_releases_the_difference(): void
    {
        [$main] = $this->twoPastDueLoans();
        $this->postTheRun()->assertCreated();

        // ₱6,000.00 of the ₱10,000.00 is paid: ₱4,000.00 at 5% needs ₱200.00.
        $main->amortizationSchedules()->first()->update(['principal_paid' => 6000, 'status' => 'partial']);

        $preview = $this->preview();
        $mainRow = $this->branchRow($preview, $this->branch);
        $this->assertSame([20_000, 50_000, -30_000], [$mainRow['required'], $mainRow['current_balance'], $mainRow['adjustment']]);
        $this->assertSame(0, $this->branchRow($preview, $this->far)['adjustment']);
        $this->assertSame(-30_000, $preview['totals']['adjustment']);

        $data = $this->postTheRun()->assertCreated()->json('data');

        $this->assertCount(1, $data['postings'], 'a branch whose allowance already matches gets no posting');
        $posting = $data['postings'][0];
        $this->assertSame([$this->branch->id, 20_000, 50_000, -30_000], [$posting['branch']['id'], $posting['required'], $posting['previous_balance'], $posting['adjustment']]);

        $journal = AccountingJournal::query()->with('lines')->findOrFail($posting['journal']['id']);
        $this->assertSame(30_000, (int) $journal->lines->firstWhere('accounting_account_id', $this->account('1200'))->debit);
        $this->assertSame(30_000, (int) $journal->lines->firstWhere('accounting_account_id', $this->account('5140'))->credit);
        $this->assertSame((int) $journal->total_debit, (int) $journal->total_credit);
        $this->assertSame(['1200' => 120_000, '5140' => 120_000], $this->balances('1200', '5140'));
        $this->assertSame(-120_000, collect($this->balanceSheet()['assets'])->firstWhere('account_code', '1200')['amount']);
    }

    public function test_a_stale_expected_adjustment_is_refused_with_nothing_written(): void
    {
        $this->twoPastDueLoans();
        $auditsBefore = AuditLog::count();

        $this->postJson('/api/accounting/allowance/postings', ['as_of' => $this->today(), 'expected_adjustment' => 149_999])
            ->assertStatus(409)
            ->assertJsonPath('message', 'The figures changed since the preview; review them again');

        $this->assertSame(0, AccountingAllowancePosting::count());
        $this->assertSame(0, AccountingJournal::query()->where('source', 'credit_loss')->count());
        $this->assertSame($auditsBefore, AuditLog::count());
    }

    public function test_a_run_dated_in_a_closed_period_is_refused(): void
    {
        $this->twoPastDueLoans();
        $lastMonthEnd = Carbon::today()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $this->postSimpleJournal('1110', '1010', 100, ['date' => $lastMonthEnd]);
        app(PeriodCalendar::class)->ensureProvisioned();
        app(PeriodCalendar::class)->close(AccountingPeriod::query()->covering($lastMonthEnd)->firstOrFail(), $this->admin);

        $preview = $this->preview($lastMonthEnd);
        $this->assertFalse($preview['can_post']);
        $this->assertCount(1, $preview['blocking_reasons']);
        $this->assertStringContainsString('closed', $preview['blocking_reasons'][0]);

        $this->postJson('/api/accounting/allowance/postings', [
            'as_of' => $lastMonthEnd,
            'expected_adjustment' => $preview['totals']['adjustment'],
        ])->assertStatus(422)->assertJsonValidationErrors('as_of');

        $this->assertSame(0, AccountingAllowancePosting::count());
    }

    public function test_a_future_date_is_refused(): void
    {
        $tomorrow = Carbon::tomorrow()->toDateString();

        $this->getJson('/api/accounting/allowance/preview?as_of='.$tomorrow)->assertStatus(422)->assertJsonValidationErrors('as_of');
        $this->postJson('/api/accounting/allowance/postings', ['as_of' => $tomorrow, 'expected_adjustment' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('as_of');
    }

    public function test_the_preview_defaults_to_today(): void
    {
        $this->assertSame($this->today(), $this->getJson('/api/accounting/allowance/preview')->assertOk()->json('data.as_of'));
    }

    public function test_a_1200_balance_on_journals_with_no_branch_blocks_the_run_until_it_is_moved_to_a_branch(): void
    {
        $this->twoPastDueLoans();
        // An opening balance posts with no branch: ₱700.00 of allowance.
        $this->postSimpleJournal('3050', '1200', 70_000, ['branch_id' => null, 'date' => Carbon::today()->subDays(3)->toDateString()]);
        // ₱90.00 already held in Main, dated after the earlier as_of below.
        $this->postSimpleJournal('5140', '1200', 9_000, ['date' => Carbon::today()->subDays(2)->toDateString()]);
        $refusal = 'The allowance has ₱700.00 on entries with no branch. Move it to the branches with one entry per branch first, then post.';

        $earlier = $this->preview(Carbon::today()->subDays(2)->subDay()->toDateString());
        $this->assertSame(0, $this->branchRow($earlier, $this->branch)['current_balance']);

        $preview = $this->preview();
        $this->assertSame(70_000, $preview['unassigned_balance']);
        $this->assertSame(9_000, $this->branchRow($preview, $this->branch)['current_balance']);
        $this->assertFalse($preview['can_post']);
        $this->assertSame([$refusal], $preview['blocking_reasons']);

        $this->postTheRun()->assertStatus(422)->assertJsonPath('errors.allowance.0', $refusal);
        $this->assertSame(0, AccountingAllowancePosting::count());
        $this->assertSame(0, AccountingJournal::query()->where('source', 'credit_loss')->count());

        // Moved to Main with a journal entry: out of the no-branch balance, into Main's.
        $this->postSimpleJournal('1200', '3050', 70_000, ['branch_id' => null]);
        $this->postSimpleJournal('3050', '1200', 70_000);

        $moved = $this->preview();
        $this->assertSame(0, $moved['unassigned_balance']);
        $this->assertTrue($moved['can_post']);
        $main = $this->branchRow($moved, $this->branch);
        $this->assertSame([79_000, -29_000], [$main['current_balance'], $main['adjustment']]);

        $this->postTheRun()->assertCreated();
        $this->assertSame(0, AccountingJournal::query()->where('source', 'credit_loss')->whereNull('branch_id')->count());
        $this->assertSame(150_000, $this->balances('1200')['1200']);
    }

    public function test_a_run_dated_before_an_earlier_posting_is_refused(): void
    {
        [$main] = $this->twoPastDueLoans();
        $this->postTheRun()->assertCreated();
        $yesterday = Carbon::yesterday()->toDateString();
        $refusal = 'An allowance was already posted as of '.Carbon::today()->format('M j, Y').'. Post only on or after that date.';

        $backDated = $this->preview($yesterday);
        $this->assertFalse($backDated['can_post']);
        $this->assertContains($refusal, $backDated['blocking_reasons']);

        $this->postJson('/api/accounting/allowance/postings', [
            'as_of' => $yesterday,
            'expected_adjustment' => $backDated['totals']['adjustment'],
        ])->assertStatus(422)->assertJsonPath('errors.as_of.0', $refusal);
        $this->assertSame(2, AccountingAllowancePosting::count());

        // The same date is still open to a run once something changes.
        $main->amortizationSchedules()->first()->update(['principal_paid' => 6000, 'status' => 'partial']);
        $this->assertNotContains($refusal, $this->preview()['blocking_reasons']);
        $this->postTheRun()->assertCreated();
    }

    public function test_the_allowance_lock_is_taken_before_any_loan_is_read(): void
    {
        $this->twoPastDueLoans();
        $expected = $this->preview()['totals']['adjustment'];

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(AllowancePoster::class)->post($this->today(), $expected, $this->admin);
        $queries = array_map(fn (array $query): string => strtolower($query['query']), DB::getQueryLog());
        DB::disableQueryLog();

        $lock = collect($queries)->search(fn (string $sql): bool => str_starts_with($sql, 'select * from `accounting_accounts`') && str_contains($sql, 'for update'));
        $firstLoanRead = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from `loans`'));

        $this->assertNotFalse($lock, 'the run never locked the allowance account');
        $this->assertNotFalse($firstLoanRead, 'the run never read the loans');
        $this->assertLessThan($firstLoanRead, $lock, 'the loans were read before the allowance lock');
        $this->assertEmpty(
            collect($queries)->filter(fn (string $sql): bool => str_contains($sql, '`loans`') && str_contains($sql, 'for update'))->all(),
            'the run locked loan rows',
        );
    }

    public function test_a_run_dated_before_a_no_branch_1200_entry_is_refused_even_when_the_balance_is_zero_on_its_date(): void
    {
        $this->twoPastDueLoans();
        $today = Carbon::today();
        $yesterday = Carbon::yesterday()->toDateString();
        // Today's opening balance posts ₱700.00 with no branch and is moved
        // to Main the same day: nothing is left with no branch, on any date.
        $this->postSimpleJournal('3050', '1200', 70_000, ['branch_id' => null, 'date' => $today->toDateString()]);
        $this->postSimpleJournal('1200', '3050', 70_000, ['branch_id' => null, 'date' => $today->toDateString()]);
        $this->postSimpleJournal('3050', '1200', 70_000, ['date' => $today->toDateString()]);
        $refusal = 'The allowance has entries with no branch dated as late as '.$today->format('M j, Y').', after this run\'s date. Post as of '.$today->format('M j, Y').' or later.';

        $backDated = $this->preview($yesterday);
        $this->assertSame(0, $backDated['unassigned_balance']);
        $this->assertFalse($backDated['can_post']);
        $this->assertSame([$refusal], $backDated['blocking_reasons']);

        $this->postJson('/api/accounting/allowance/postings', [
            'as_of' => $yesterday,
            'expected_adjustment' => $backDated['totals']['adjustment'],
        ])->assertStatus(422)->assertJsonPath('errors.allowance.0', $refusal);
        $this->assertSame(0, AccountingAllowancePosting::count());
        $this->assertSame(0, AccountingJournal::query()->where('source', 'credit_loss')->count());

        // On the entries' own date the run posts.
        $this->assertNotContains($refusal, $this->preview()['blocking_reasons']);
        $this->postTheRun()->assertCreated();
    }

    public function test_the_later_posting_check_is_a_locking_read_taken_after_the_allowance_lock(): void
    {
        $this->twoPastDueLoans();
        $expected = $this->preview()['totals']['adjustment'];

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(AllowancePoster::class)->post($this->today(), $expected, $this->admin);
        $queries = array_map(fn (array $query): string => strtolower($query['query']), DB::getQueryLog());
        DB::disableQueryLog();

        $lock = collect($queries)->search(fn (string $sql): bool => str_starts_with($sql, 'select * from `accounting_accounts`') && str_contains($sql, 'for update'));
        $laterCheck = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from `accounting_allowance_postings`') && str_contains($sql, '`as_of` >'));

        $this->assertNotFalse($laterCheck, 'the run never checked for a later posting');
        $this->assertStringContainsString('for update', $queries[$laterCheck], 'the later-posting check is a plain read');
        $this->assertLessThan($laterCheck, $lock, 'the later-posting check ran before the allowance lock');
    }

    public function test_postings_are_listed_newest_first_with_totals_over_every_row(): void
    {
        [$main] = $this->twoPastDueLoans();
        $first = $this->postTheRun()->assertCreated()->json('data');
        $main->amortizationSchedules()->first()->update(['principal_paid' => 6000, 'status' => 'partial']);
        $second = $this->postTheRun()->assertCreated()->json('data');

        $page = $this->getJson('/api/accounting/allowance/postings?per_page=2')->assertOk()->json();

        $this->assertSame(3, $page['meta']['total']);
        $this->assertSame(2, $page['meta']['per_page']);
        $this->assertSame(150_000 - 30_000, $page['meta']['totals']['adjustment']);
        $this->assertSame($second['postings'][0]['id'], $page['data'][0]['id']);
        $this->assertSame(
            ['id', 'branch', 'required', 'previous_balance', 'adjustment', 'journal', 'run_id', 'as_of', 'created_by', 'created_at'],
            array_keys($page['data'][0]),
        );
        $this->assertSame($second['run_id'], $page['data'][0]['run_id']);
        $this->assertSame(['id' => $this->admin->id, 'name' => $this->admin->full_name], $page['data'][0]['created_by']);
        $this->assertSame($first['run_id'], $page['data'][1]['run_id']);

        $this->assertSame(25, $this->getJson('/api/accounting/allowance/postings')->json('meta.per_page'));
        $this->getJson('/api/accounting/allowance/postings?per_page=101')->assertStatus(422);
    }
}
