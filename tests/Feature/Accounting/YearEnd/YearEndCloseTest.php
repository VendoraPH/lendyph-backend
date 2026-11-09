<?php

namespace Tests\Feature\Accounting\YearEnd;

use App\Models\AccountingJournal;
use App\Models\AccountingPeriod;
use App\Models\AccountingYearClose;
use App\Models\AccountingYearCloseEntry;
use App\Models\AuditLog;
use App\Services\Accounting\TrialBalanceBuilder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * POST /accounting/year-closes and GET /accounting/year-closes: one `closing`
 * journal per branch with activity (plus one for journals with no branch),
 * dated 31 December, moving each income and expense account's net for the
 * year to the `net_surplus` account; then the year's open months are closed.
 */
class YearEndCloseTest extends TestCase
{
    use PostsJournals, SetupLendyPH, YearEndFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpYearEnd();
    }

    public function test_the_close_answers_the_row_in_the_contract_shape(): void
    {
        $this->postTheYear();

        $row = $this->closed();

        $this->assertSame([
            'id', 'year', 'status', 'net_surplus', 'net_surplus_account', 'entries', 'periods_closed', 'closed_at',
            'closed_by', 'reopened_at', 'reopened_by', 'reopen_reason',
        ], array_keys($row));
        $this->assertSame(2025, $row['year']);
        $this->assertSame('closed', $row['status']);
        $this->assertSame(self::YEAR_NET, $row['net_surplus']);
        $this->assertSame(['id' => $this->account('3030'), 'code' => '3030', 'name' => 'Retained Earnings'], $row['net_surplus_account']);
        $this->assertSame(['branch', 'journal', 'net_surplus'], array_keys($row['entries'][0]));
        $this->assertSame(['id', 'journal_no'], array_keys($row['entries'][0]['journal']));
        $this->assertSame(['id' => $this->admin->id, 'name' => $this->admin->full_name], $row['closed_by']);
        $this->assertNotNull($row['closed_at']);
        $this->assertNull($row['reopened_at']);
        $this->assertNull($row['reopened_by']);
        $this->assertNull($row['reopen_reason']);
    }

    public function test_it_posts_one_balanced_closing_journal_per_branch_dated_31_december(): void
    {
        $this->postTheYear();

        $row = $this->closed();

        $journals = AccountingJournal::query()->where('source', 'closing')->with('lines')->orderBy('id')->get();
        $this->assertCount(3, $journals);
        $this->assertEqualsCanonicalizing([$this->branch->id, $this->far->id, null], $journals->pluck('branch_id')->all());

        foreach ($journals as $journal) {
            $this->assertSame('posted', $journal->status);
            $this->assertSame('2025-12-31', $journal->date->format('Y-m-d'));
            $this->assertSame('Year-end close 2025', $journal->description);
            $this->assertSame($journal->total_debit, $journal->total_credit);
            $this->assertSame(AccountingYearCloseEntry::class, $journal->postable_type);
        }

        $main = $journals->firstWhere('branch_id', $this->branch->id);
        $this->assertSame([
            ['4010', 8900000, 0],
            ['5020', 0, 1500000],
            ['3030', 0, self::MAIN_NET],
        ], $this->linesOf($main));

        $far = $journals->firstWhere('branch_id', $this->far->id);
        $this->assertSame([
            ['4010', 2000000, 0],
            ['4020', 100050, 0],
            ['5010', 0, 3000000],
            ['3030', -self::FAR_NET, 0],
        ], $this->linesOf($far), 'a loss debits net surplus');

        $none = $journals->firstWhere('branch_id', null);
        $this->assertSame([
            ['4070', 400000, 0],
            ['5010', 20000, 0],
            ['3030', 0, self::NO_BRANCH_NET],
        ], $this->linesOf($none), 'an expense with a credit net is debited');

        $entries = collect($row['entries']);
        $this->assertSame(
            [self::FAR_NET, self::MAIN_NET, self::NO_BRANCH_NET],
            $entries->pluck('net_surplus')->all(),
        );
        $this->assertSame($journals->pluck('journal_no')->sort()->values()->all(), $entries->pluck('journal.journal_no')->sort()->values()->all());
        $this->assertSame(self::YEAR_NET, $entries->sum('net_surplus'));
    }

    public function test_after_the_close_income_and_expense_net_to_zero_for_the_year_and_net_surplus_holds_the_result(): void
    {
        $this->postTheYear();
        $this->closed();

        $rows = collect(app(TrialBalanceBuilder::class)->build('2025-12-31')['rows']);

        $this->assertSame([], $rows->whereIn('type', ['income', 'expense'])->values()->all());
        $retained = $rows->firstWhere('account_code', '3030');
        $this->assertSame(self::YEAR_NET, $retained['credit'] - $retained['debit']);
        $this->assertTrue(app(TrialBalanceBuilder::class)->build('2025-12-31')['is_balanced']);

        foreach ([$this->branch->id => self::MAIN_NET, $this->far->id => self::FAR_NET] as $branchId => $net) {
            $branchRows = collect(app(TrialBalanceBuilder::class)->build('2025-12-31', $branchId)['rows']);
            $this->assertSame([], $branchRows->whereIn('type', ['income', 'expense'])->values()->all());
            $row = $branchRows->firstWhere('account_code', '3030');
            $this->assertSame($net, $row['credit'] - $row['debit']);
        }

        // The following year's income is untouched.
        $next = collect(app(TrialBalanceBuilder::class)->build('2026-01-31')['rows']);
        $this->assertSame(77700, $next->firstWhere('account_code', '4010')['credit']);
    }

    public function test_it_closes_the_years_open_months_and_leaves_already_closed_ones_as_they_were(): void
    {
        $this->postTheYear();
        $june = $this->closePeriod('2025-06');
        $juneClosedAt = $june->closed_at->toIso8601String();

        $row = $this->closed();

        $year = AccountingPeriod::query()->where('code', 'like', '2025-%')->orderBy('code')->get();
        $this->assertCount(12, $year, 'every month of the year has its period, January and February included');
        $this->assertSame(array_fill(0, 12, 'closed'), $year->pluck('status')->all());

        $closedByTheClose = $year->reject(fn (AccountingPeriod $period) => $period->code === '2025-06');
        $this->assertSame($closedByTheClose->pluck('id')->values()->all(), array_column($row['periods_closed'], 'id'));
        $this->assertSame($closedByTheClose->pluck('name')->values()->all(), array_column($row['periods_closed'], 'name'));

        $june->refresh();
        $this->assertSame($juneClosedAt, $june->closed_at->toIso8601String());
        $this->assertSame($this->admin->id, $june->closed_by);

        $this->assertSame('open', $this->period('2026-01')->status, 'the next year stays open');
        $this->assertSame(
            $closedByTheClose->pluck('id')->values()->all(),
            AccountingYearClose::query()->sole()->periods_closed,
        );
    }

    public function test_a_posting_into_the_closed_year_is_refused(): void
    {
        $this->postTheYear();
        $this->closed();

        $this->expectException(ValidationException::class);
        $this->postSimpleJournal('1010', '4010', 100, ['date' => '2025-02-10']);
    }

    public function test_a_loss_for_the_year_debits_net_surplus(): void
    {
        $this->postSimpleJournal('1010', '4010', 100000, ['date' => '2025-05-01']);
        $this->postSimpleJournal('5020', '1010', 350000, ['date' => '2025-05-02']);

        $row = $this->closed();

        $this->assertSame(-250000, $row['net_surplus']);
        $journal = AccountingJournal::query()->where('source', 'closing')->with('lines')->sole();
        $this->assertSame([['4010', 100000, 0], ['5020', 0, 350000], ['3030', 250000, 0]], $this->linesOf($journal));
    }

    public function test_a_year_with_nothing_to_close_still_closes_its_months(): void
    {
        $this->postSimpleJournal('1010', '3010', 100000, ['date' => '2025-05-01']);

        $row = $this->closed();

        $this->assertSame(0, $row['net_surplus']);
        $this->assertSame([], $row['entries']);
        $this->assertSame(0, AccountingJournal::query()->where('source', 'closing')->count());
        $this->assertCount(12, $row['periods_closed']);
    }

    public function test_a_second_close_of_the_same_year_answers_409(): void
    {
        $this->postTheYear();
        $this->closed();

        $this->closeYear()->assertStatus(409)->assertJsonPath('message', '2025 is already closed.');

        $this->assertSame(1, AccountingYearClose::query()->count());
        $this->assertSame(3, AccountingJournal::query()->where('source', 'closing')->count());
    }

    public function test_the_database_holds_one_live_close_per_year_and_keeps_reopened_ones(): void
    {
        $row = fn (?int $closedYear, string $status): array => [
            'year' => 2025,
            'status' => $status,
            'closed_year' => $closedYear,
            'net_surplus_account_id' => $this->account('3030'),
            'net_surplus' => 0,
            'periods_closed' => '[]',
            'closed_by' => $this->admin->id,
            'closed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        AccountingYearClose::query()->insert($row(null, 'reopened'));
        AccountingYearClose::query()->insert($row(null, 'reopened'));
        AccountingYearClose::query()->insert($row(2025, 'closed'));

        $this->expectException(UniqueConstraintViolationException::class);
        AccountingYearClose::query()->insert($row(2025, 'closed'));
    }

    public function test_a_blocked_close_answers_422_with_its_reasons_on_year_and_writes_nothing(): void
    {
        $this->postTheYear();
        $this->closePeriod('2025-12');

        $this->closeYear()
            ->assertUnprocessable()
            ->assertJsonPath('errors.year', ['December 2025 is closed. Reopen it so the closing entries can be dated Dec 31, 2025.']);

        $this->closeYear(2026)
            ->assertUnprocessable()
            ->assertJsonPath('errors.year.0', '2026 ends on Dec 31, 2026; it can be closed from Jan 1, 2027.');

        $this->assertSame(0, AccountingYearClose::query()->count());
        $this->assertSame(0, AccountingJournal::query()->where('source', 'closing')->count());
        $this->assertSame('open', $this->period('2025-11')->status);
    }

    public function test_the_year_is_validated_on_close(): void
    {
        $this->postJson('/api/accounting/year-closes', [])->assertUnprocessable()->assertJsonValidationErrors('year');
        $this->postJson('/api/accounting/year-closes', ['year' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('year');
    }

    public function test_the_close_writes_one_audit_row_with_the_year_net_surplus_entries_and_periods(): void
    {
        $this->postTheYear();

        $row = $this->closed();

        $audit = AuditLog::query()->where('action', 'year_closed')->sole();
        $this->assertSame(AccountingYearClose::class, $audit->auditable_type);
        $this->assertSame($row['id'], $audit->auditable_id);
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame(2025, $audit->new_values['year']);
        $this->assertSame(self::YEAR_NET, $audit->new_values['net_surplus']);
        $this->assertCount(3, $audit->new_values['entries']);
        $this->assertSame(array_column($row['periods_closed'], 'id'), $audit->new_values['periods_closed']);
        $this->assertSame(1, AuditLog::query()->where('auditable_type', AccountingYearClose::class)->count());
    }

    public function test_the_audit_trail_reads_the_closes_net_surplus_in_pesos(): void
    {
        $this->postTheYear();
        $this->closed();

        $audit = AuditLog::query()->where('action', 'year_closed')->sole();
        $changes = collect($this->getJson("/api/audit-logs/{$audit->id}")->assertOk()->json('data.changes'))->keyBy('field');

        $this->assertSame('₱69,200.50', $changes['net_surplus']['new']);
    }

    /**
     * The books' coverage is checked before the close's transaction: with no
     * journal yet, the close adds no month of 2025. A first journal into 2025
     * posted in that moment used to let the close commit with no month closed,
     * so 2025 kept taking postings. The close answers 409 instead, and the
     * retry adds the twelve months and closes them.
     */
    public function test_a_close_racing_the_first_journal_into_the_year_answers_409_and_the_retry_closes_every_month(): void
    {
        $raced = false;

        DB::listen(function (QueryExecuted $query) use (&$raced): void {
            if ($raced || ! str_starts_with(strtolower($query->sql), 'select min(`date`)')) {
                return;
            }

            $raced = true;
            $this->postSimpleJournal('1010', '4010', 500000, ['date' => '2025-06-15', 'branch_id' => $this->branch->id]);
        });

        $this->closeYear()
            ->assertStatus(409)
            ->assertJsonPath('message', 'Another year-end close or reopen of 2025 was saved at the same time. Reload and try again.');

        $this->assertTrue($raced, 'the journal never raced the close, so this test proved nothing');
        $this->assertSame(0, AccountingYearClose::query()->count());
        $this->assertSame(0, AccountingJournal::query()->where('source', 'closing')->count());

        $this->closed();

        $closedMonths = AccountingPeriod::query()->where('code', 'like', '2025-%')->where('status', 'closed')->pluck('id')->sort()->values()->all();
        $this->assertCount(12, $closedMonths);
        $this->assertSame($closedMonths, collect(AccountingYearClose::query()->sole()->periods_closed)->map(fn ($id): int => (int) $id)->sort()->values()->all());
    }

    public function test_the_close_needs_accounting_close_and_view_all(): void
    {
        $this->postTheYear();

        $this->actingAs($this->userWith(['accounting:view', 'accounting:close']));
        $this->closeYear()->assertForbidden();

        $this->actingAs($this->userWith(['accounting:view', 'branches:view_all']));
        $this->closeYear()->assertForbidden();

        $this->assertSame(0, AccountingYearClose::query()->count());

        $this->actingAs($this->userWith(['accounting:close', 'branches:view_all']));
        $this->closeYear()->assertCreated();
    }

    public function test_the_list_is_newest_year_first_with_totals(): void
    {
        $this->postSimpleJournal('1010', '4010', 50000, ['date' => '2024-03-01']);
        $this->postTheYear();
        $this->closed(2024);
        $this->closed(2025);

        $response = $this->getJson('/api/accounting/year-closes')->assertOk();

        $this->assertSame([2025, 2024], array_column($response->json('data'), 'year'));
        $this->assertSame(self::YEAR_NET + 50000, $response->json('meta.totals.net_surplus'));
        $this->assertSame(25, $response->json('meta.per_page'));
        $this->assertSame(2, $response->json('meta.total'));

        $this->assertSame(100, $this->getJson('/api/accounting/year-closes?per_page=500')->assertOk()->json('meta.per_page'));
    }

    public function test_a_branch_user_sees_only_their_branches_entries_and_their_sum(): void
    {
        $this->postTheYear();
        $this->closed();

        $this->actingAs($this->userWith(['accounting:view'], $this->branch));
        $response = $this->getJson('/api/accounting/year-closes')->assertOk();

        $row = $response->json('data.0');
        $this->assertSame([['id' => $this->branch->id, 'name' => $this->branch->name]], array_column($row['entries'], 'branch'));
        $this->assertSame(self::MAIN_NET, $row['net_surplus']);
        $this->assertSame(self::MAIN_NET, $response->json('meta.totals.net_surplus'));

        $this->actingAs($this->userWith(['accounting:view'], $this->branch, $this->far));
        $row = $this->getJson('/api/accounting/year-closes')->assertOk()->json('data.0');
        $this->assertSame(self::MAIN_NET + self::FAR_NET, $row['net_surplus']);
        $this->assertNotContains(null, array_column($row['entries'], 'branch'), 'the no-branch entry is for view_all only');

        $this->actingAs($this->userWith(['accounting:view', 'branches:view_all']));
        $row = $this->getJson('/api/accounting/year-closes')->assertOk()->json('data.0');
        $this->assertCount(3, $row['entries']);
        $this->assertSame(self::YEAR_NET, $row['net_surplus']);
    }

    public function test_the_list_needs_accounting_view(): void
    {
        $this->actingAs($this->userWithNoRole());

        $this->getJson('/api/accounting/year-closes')->assertForbidden();
    }

    /**
     * @return list<array{0: string, 1: int, 2: int}>
     */
    private function linesOf(AccountingJournal $journal): array
    {
        $codes = array_flip($this->accountIds);

        return $journal->lines->sortBy('line_no')->map(fn ($line): array => [
            (string) $codes[$line->accounting_account_id],
            (int) $line->debit,
            (int) $line->credit,
        ])->values()->all();
    }
}
