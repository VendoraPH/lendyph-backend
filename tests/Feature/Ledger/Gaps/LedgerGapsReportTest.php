<?php

namespace Tests\Feature\Ledger\Gaps;

use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\GCashTransaction;
use App\Models\LoanAdjustment;
use App\Models\Repayment;
use App\Models\Role;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * `GET /accounting/ledger-gaps`: past events that posted nothing, or posted
 * differently from today's rules (Phase 2, Accounting decision 13). Nothing
 * is back-posted, so each kind is listed while its journal is missing or
 * old-style, and drops out once the event posts the new way.
 *
 * Events are made before the chart is seeded, so today's paths post nothing
 * for them; each test then posts only what it is about.
 */
class LedgerGapsReportTest extends TestCase
{
    use BuildsLedgerGaps, PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── Each kind ──

    public function test_a_released_loan_with_no_release_journal_is_listed_until_it_posts(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();

        $row = $this->rowsOf($this->gaps()->assertOk(), 'release')[$loan->id] ?? null;

        $this->assertNotNull($row, 'The release with no journal is not listed.');
        $this->assertSame($loan->loan_account_number, $row['reference']);
        $this->assertSame($loan->released_at->toDateString(), $row['date']);
        $this->assertSame('none', $row['posted']);
        $this->assertSame('60000.00', $row['amount']);
        $this->assertSame(['id' => $this->branch->id, 'name' => $this->branch->name], $row['branch']);
        $this->assertSame(['name' => $loan->borrower->full_name, 'code' => $loan->borrower->borrower_code], $row['member']);
        $this->assertNotSame('', $row['reason']);

        $this->poster()->loanRelease($loan, $this->admin->id);

        $this->assertArrayNotHasKey($loan->id, $this->rowsOf($this->gaps(), 'release'));
    }

    public function test_a_payment_with_no_collection_journal_is_listed_until_it_posts(): void
    {
        $loan = $this->createReleasedLoan();
        $repayment = $this->pay($loan);
        $voided = $this->pay($loan);
        DB::table('repayments')->where('id', $voided->id)->update(['status' => 'voided']);
        $this->seedChartOfAccounts();

        $rows = $this->rowsOf($this->gaps()->assertOk(), 'collection');

        $this->assertSame('11800.00', $rows[$repayment->id]['amount'] ?? null);
        $this->assertSame($repayment->receipt_number, $rows[$repayment->id]['reference']);
        $this->assertSame('none', $rows[$repayment->id]['posted']);
        $this->assertArrayNotHasKey($voided->id, $rows, 'A voided payment that posted nothing moved no money on the books.');

        $this->poster()->loanCollection($repayment->fresh(), $this->admin->id);

        $this->assertArrayNotHasKey($repayment->id, $this->rowsOf($this->gaps(), 'collection'));
    }

    public function test_a_build_up_credited_to_other_liabilities_is_listed_and_one_credited_to_share_capital_is_not(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['scb_amount' => 500]);
        $old = $this->pay($loan, 12550);
        $this->assertDatabaseHas('share_capital_ledger', ['repayment_id' => $old->id, 'credit' => 750]);
        $this->seedChartOfAccounts();

        // The collection as it posted before decision 5: the build-up held on 2300.
        $this->postJournal([
            ['account_id' => $this->account('1010'), 'debit' => 1_255_000, 'credit' => 0],
            ['account_id' => $this->account('1110'), 'debit' => 0, 'credit' => 1_000_000],
            ['account_id' => $this->account('4010'), 'debit' => 0, 'credit' => 180_000],
            ['account_id' => $this->account('2300'), 'debit' => 0, 'credit' => 75_000],
        ], ['source' => 'loan_collection', 'postable_type' => Repayment::class, 'postable_id' => $old->id]);

        $new = $this->pay($loan->fresh(), 12550);

        $response = $this->gaps()->assertOk();
        $buildUps = $this->rowsOf($response, 'build_up');

        $this->assertSame('750.00', $buildUps[$old->id]['amount'] ?? null);
        $this->assertSame('differently', $buildUps[$old->id]['posted']);
        $this->assertSame($old->receipt_number, $buildUps[$old->id]['reference']);
        $this->assertStringContainsString('2300', $buildUps[$old->id]['reason']);
        $this->assertStringContainsString('3060', $buildUps[$old->id]['reason']);
        $this->assertArrayNotHasKey($new->id, $buildUps, 'A build-up posted by today\'s rule is listed.');
        $this->assertArrayNotHasKey($old->id, $this->rowsOf($response, 'collection'), 'A payment with a collection journal is listed as unposted.');
        $this->assertArrayNotHasKey($new->id, $this->rowsOf($response, 'collection'));
    }

    public function test_a_restructure_posted_as_an_ordinary_release_is_listed_and_one_posted_by_the_new_rule_is_not(): void
    {
        $old = $this->restructureOf($this->createReleasedLoan(), $this->createReleasedLoan());
        $new = $this->restructureOf($this->createReleasedLoan(), $source = $this->createReleasedLoan(), [5_000_000, 700_000, 300_000, 0]);
        $this->seedChartOfAccounts();

        $this->poster()->loanRelease($old, $this->admin->id);
        $this->poster()->restructureRelease($new, $source, $this->admin->id, 'cash');

        $response = $this->gaps()->assertOk();
        $restructures = $this->rowsOf($response, 'restructure');

        $this->assertSame('differently', $restructures[$old->id]['posted'] ?? null);
        $this->assertSame('60000.00', $restructures[$old->id]['amount']);
        $this->assertStringContainsString('ordinary release', $restructures[$old->id]['reason']);
        $this->assertArrayNotHasKey($new->id, $restructures);
        $this->assertArrayNotHasKey($old->id, $this->rowsOf($response, 'release'), 'A restructure with a release journal is listed as unposted.');
        $this->assertArrayNotHasKey($new->id, $this->rowsOf($response, 'release'));
    }

    public function test_an_applied_balance_adjustment_with_no_journal_is_listed_until_it_posts(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        $applied = $this->adjustment($loan->id, 'balance_adjustment', 'applied', -2500);
        $pending = $this->adjustment($loan->id, 'balance_adjustment', 'pending', -1000);
        $waiver = $this->adjustment($loan->id, 'penalty_waiver', 'applied', null);

        $rows = $this->rowsOf($this->gaps()->assertOk(), 'adjustment');

        $this->assertSame('2500.00', $rows[$applied->id]['amount'] ?? null);
        $this->assertSame($applied->adjustment_number, $rows[$applied->id]['reference']);
        $this->assertStringContainsString('reduction', $rows[$applied->id]['reason']);
        $this->assertArrayNotHasKey($pending->id, $rows);
        $this->assertArrayNotHasKey($waiver->id, $rows);

        $this->poster()->balanceAdjustment($applied, -250_000, $this->admin->id);

        $this->assertArrayNotHasKey($applied->id, $this->rowsOf($this->gaps(), 'adjustment'));
    }

    public function test_share_capital_entries_with_no_journal_are_listed_until_they_post(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $credit = ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'date' => '2026-10-04', 'credit' => 5000, 'debit' => 0]);
        $debit = ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'date' => '2026-10-05', 'credit' => 0, 'debit' => 2000]);
        $autoCredit = ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'description' => 'Monthly pledge - auto-credit', 'credit' => 300, 'debit' => 0]);
        $fromRelease = ShareCapitalLedger::factory()->create(['borrower_id' => $loan->borrower_id, 'loan_id' => $loan->id, 'credit' => 500, 'debit' => 0]);

        $rows = $this->rowsOf($this->gaps()->assertOk(), 'share_capital');

        $this->assertSame('5000.00', $rows[$credit->id]['amount'] ?? null);
        $this->assertSame('2026-10-04', $rows[$credit->id]['date']);
        $this->assertSame($credit->reference, $rows[$credit->id]['reference']);
        $this->assertStringContainsString('contribution', $rows[$credit->id]['reason']);
        $this->assertSame('2000.00', $rows[$debit->id]['amount'] ?? null);
        $this->assertStringContainsString('withdrawal', $rows[$debit->id]['reason']);
        $this->assertStringContainsString('Auto-credit', $rows[$autoCredit->id]['reason'] ?? '');
        $this->assertArrayNotHasKey($fromRelease->id, $rows, 'A release\'s share capital is in its release journal, not a journal of its own.');

        $this->poster()->shareCapitalEntry($credit, 'cash', $this->admin->id);
        $this->poster()->shareCapitalEntry($debit, 'cash', $this->admin->id);

        $after = $this->rowsOf($this->gaps(), 'share_capital');
        $this->assertArrayNotHasKey($credit->id, $after);
        $this->assertArrayNotHasKey($debit->id, $after);
        $this->assertArrayHasKey($autoCredit->id, $after);
    }

    public function test_paid_cash_ins_and_cash_outs_with_no_journal_are_listed_until_they_post(): void
    {
        $this->seedChartOfAccounts();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $paid = GCashTransaction::factory()->create([
            'type' => 'cash_in', 'amount' => 5000, 'charge_amount' => 20, 'total_amount' => 5020,
            'status' => 'paid', 'paid_at' => '2026-10-05 10:00:00', 'transaction_date' => '2026-10-03 09:00:00',
            'borrower_id' => $member->id,
        ]);
        $pending = GCashTransaction::factory()->create([
            'type' => 'cash_in', 'amount' => 5000, 'charge_amount' => 20, 'total_amount' => 5020,
            'status' => 'pending', 'paid_at' => null, 'borrower_id' => $member->id,
        ]);
        $walkIn = GCashTransaction::factory()->forNonMember()->create([
            'type' => 'cash_out', 'amount' => 3000, 'charge_amount' => 20, 'total_amount' => 2980,
            'status' => 'completed', 'transaction_date' => '2026-10-06 09:00:00',
        ]);

        $response = $this->gaps()->assertOk();
        $cashIns = $this->rowsOf($response, 'cash_in');
        $cashOuts = $this->rowsOf($response, 'cash_out');

        $this->assertSame('5000.00', $cashIns[$paid->id]['amount'] ?? null);
        $this->assertSame('2026-10-05', $cashIns[$paid->id]['date']);
        $this->assertSame($paid->reference_no, $cashIns[$paid->id]['reference']);
        $this->assertSame($this->branch->id, $cashIns[$paid->id]['branch']['id']);
        $this->assertArrayNotHasKey($pending->id, $cashIns);
        $this->assertSame('3000.00', $cashOuts[$walkIn->id]['amount'] ?? null);
        $this->assertSame('2026-10-06', $cashOuts[$walkIn->id]['date']);
        $this->assertNull($cashOuts[$walkIn->id]['branch']);
        $this->assertNull($cashOuts[$walkIn->id]['member']);
        $this->assertStringContainsString('Walk-in', $cashOuts[$walkIn->id]['reason']);

        $this->poster()->gcashTransaction($paid, $this->admin->id);
        $this->poster()->gcashTransaction($walkIn, $this->admin->id);

        $after = $this->gaps();
        $this->assertSame([], $this->rowsOf($after, 'cash_in'));
        $this->assertSame([], $this->rowsOf($after, 'cash_out'));
    }

    /** AutomaticPoster::buildCollection() credits a build-up only out of a stored overpayment. */
    public function test_a_build_up_row_on_a_payment_with_no_overpayment_is_not_listed(): void
    {
        $loan = $this->createReleasedLoan();
        $this->seedChartOfAccounts();
        $repayment = $this->pay($loan);
        $this->assertSame(0.0, (float) $repayment->overpayment);
        ShareCapitalLedger::factory()->create(['borrower_id' => $loan->borrower_id, 'repayment_id' => $repayment->id, 'credit' => 500, 'debit' => 0]);

        $response = $this->gaps()->assertOk();

        $this->assertSame([], $this->rowsOf($response, 'build_up'), 'Today\'s rule would post this payment exactly as it did, so it would be listed forever.');
        $this->assertSame([], $this->rowsOf($response, 'collection'));
    }

    public function test_imported_loans_are_marked_and_can_be_left_out_or_shown_alone(): void
    {
        $imported = $this->createReleasedLoan();
        $imported->update(['external_loan_no' => 'OLD-0001']);
        $here = $this->createReleasedLoan();
        $repayment = $this->pay($imported);
        $this->seedChartOfAccounts();
        $entry = ShareCapitalLedger::factory()->create(['borrower_id' => $here->borrower_id, 'credit' => 100, 'debit' => 0]);

        $all = $this->gaps()->assertOk();
        $this->assertTrue($this->rowsOf($all, 'release')[$imported->id]['imported']);
        $this->assertStringContainsString('Imported', $this->rowsOf($all, 'release')[$imported->id]['reason']);
        $this->assertTrue($this->rowsOf($all, 'collection')[$repayment->id]['imported']);
        $this->assertFalse($this->rowsOf($all, 'release')[$here->id]['imported']);
        $this->assertFalse($this->rowsOf($all, 'share_capital')[$entry->id]['imported']);
        $this->assertSame($all->json('meta.totals'), $this->gaps(['imported' => 'include'])->json('meta.totals'));

        $excluded = $this->gaps(['imported' => 'exclude'])->assertOk();
        $this->assertSame([$here->id], array_keys($this->rowsOf($excluded, 'release')));
        $this->assertSame([], $this->rowsOf($excluded, 'collection'));
        $this->assertArrayHasKey($entry->id, $this->rowsOf($excluded, 'share_capital'));
        $this->assertSame(2, $excluded->json('meta.totals.count'));

        $only = $this->gaps(['imported' => 'only'])->assertOk();
        $this->assertEqualsCanonicalizing(['release:'.$imported->id, 'collection:'.$repayment->id], collect($only->json('data'))->map(fn (array $row): string => "{$row['kind']}:{$row['record_id']}")->all());
        $this->assertSame(['count' => 1, 'amount' => '60000.00'], $only->json('meta.totals.by_kind.release'));
        $this->assertSame(['count' => 0, 'amount' => '0.00'], $only->json('meta.totals.by_kind.share_capital'));

        $this->gaps(['imported' => 'sometimes'])->assertUnprocessable()->assertJsonValidationErrors('imported');
    }

    /** A timestamp is filtered by its Manila day: the last minute of `to` is in, the first of the next day out. */
    public function test_dates_filter_a_timestamp_by_its_day(): void
    {
        $in = $this->createReleasedLoan();
        $out = $this->createReleasedLoan();
        DB::table('loans')->where('id', $in->id)->update(['released_at' => '2026-10-31 23:59:00']);
        DB::table('loans')->where('id', $out->id)->update(['released_at' => '2026-11-01 00:00:00']);
        $this->seedChartOfAccounts();

        $october = $this->rowsOf($this->gaps(['kind' => 'release', 'from' => '2026-10-31', 'to' => '2026-10-31'])->assertOk(), 'release');

        $this->assertSame([$in->id], array_keys($october));
        $this->assertSame('2026-10-31', $october[$in->id]['date']);
    }

    // ── Totals, paging and filters ──

    public function test_totals_equal_the_rows_and_cover_every_page(): void
    {
        $this->seedChartOfAccounts();
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        foreach (range(1, 27) as $i) {
            ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'date' => '2026-10-01', 'credit' => 100 + $i / 100, 'debit' => 0]);
        }

        GCashTransaction::factory()->create([
            'type' => 'cash_in', 'amount' => 1234.56, 'charge_amount' => 20, 'total_amount' => 1254.56,
            'status' => 'paid', 'paid_at' => '2026-10-02 10:00:00', 'borrower_id' => $member->id,
        ]);

        $first = $this->gaps()->assertOk();
        $second = $this->gaps(['page' => 2])->assertOk();

        $this->assertCount(25, $first->json('data'));
        $this->assertCount(3, $second->json('data'));
        $this->assertSame(28, $first->json('meta.total'));
        $this->assertSame(25, $first->json('meta.per_page'));

        $rows = collect([...$first->json('data'), ...$second->json('data')]);
        $this->assertSame(28, $rows->map(fn (array $row): string => "{$row['kind']}:{$row['record_id']}")->unique()->count(), 'A row is on both pages.');

        $totals = $first->json('meta.totals');
        $this->assertSame(28, $totals['count']);
        $this->assertSame(['count' => 27, 'amount' => '2703.78'], $totals['by_kind']['share_capital']);
        $this->assertSame(['count' => 1, 'amount' => '1234.56'], $totals['by_kind']['cash_in']);
        $this->assertSame(['count' => 0, 'amount' => '0.00'], $totals['by_kind']['release']);

        foreach ($totals['by_kind'] as $kind => $total) {
            $kindRows = $rows->where('kind', $kind);
            $this->assertSame($total['count'], $kindRows->count(), "{$kind} count");
            $this->assertSame(
                $total['amount'],
                $this->pesos((int) $kindRows->sum(fn (array $row): int => (int) str_replace('.', '', $row['amount']))),
                "{$kind} amount",
            );
        }

        $this->assertSame($first->json('meta.totals'), $second->json('meta.totals'));
    }

    public function test_kind_dates_and_branch_filter_the_rows_and_their_totals(): void
    {
        $this->seedChartOfAccounts();
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $here = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $there = Borrower::factory()->create(['branch_id' => $far->id]);
        $early = ShareCapitalLedger::factory()->create(['borrower_id' => $here->id, 'date' => '2026-09-01', 'credit' => 100, 'debit' => 0]);
        $late = ShareCapitalLedger::factory()->create(['borrower_id' => $here->id, 'date' => '2026-10-15', 'credit' => 200, 'debit' => 0]);
        $farRow = ShareCapitalLedger::factory()->create(['borrower_id' => $there->id, 'date' => '2026-10-15', 'credit' => 400, 'debit' => 0]);
        GCashTransaction::factory()->create([
            'type' => 'cash_in', 'amount' => 1000, 'charge_amount' => 20, 'total_amount' => 1020,
            'status' => 'paid', 'paid_at' => '2026-10-15 10:00:00', 'borrower_id' => $here->id,
        ]);

        $byKind = $this->gaps(['kind' => 'share_capital'])->assertOk();
        $this->assertSame(['share_capital'], collect($byKind->json('data'))->pluck('kind')->unique()->values()->all());
        $this->assertSame(3, $byKind->json('meta.totals.count'));
        $this->assertSame(0, $byKind->json('meta.totals.by_kind.cash_in.count'));

        $dated = $this->gaps(['kind' => 'share_capital', 'from' => '2026-10-01', 'to' => '2026-10-31'])->assertOk();
        $this->assertEqualsCanonicalizing([$late->id, $farRow->id], collect($dated->json('data'))->pluck('record_id')->all());
        $this->assertSame('600.00', $dated->json('meta.totals.by_kind.share_capital.amount'));

        $branch = $this->gaps(['kind' => 'share_capital', 'branch_id' => $far->id])->assertOk();
        $this->assertSame([$farRow->id], collect($branch->json('data'))->pluck('record_id')->all());

        $this->assertArrayNotHasKey($early->id, $this->rowsOf($this->gaps(['from' => '2026-10-01']), 'share_capital'));

        $this->gaps(['kind' => 'nonsense'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->gaps(['from' => '2026-10-31', 'to' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    // ── Who may read it ──

    public function test_it_needs_accounting_view(): void
    {
        $this->seedChartOfAccounts();
        $this->actingAs($this->userWithNoRole());

        $this->gaps()->assertForbidden();
    }

    public function test_a_branch_user_sees_only_their_branches_and_rows_with_no_branch_need_view_all(): void
    {
        $this->seedChartOfAccounts();
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $here = ShareCapitalLedger::factory()->create(['borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id, 'credit' => 100, 'debit' => 0]);
        $there = ShareCapitalLedger::factory()->create(['borrower_id' => Borrower::factory()->create(['branch_id' => $far->id])->id, 'credit' => 200, 'debit' => 0]);
        $walkIn = GCashTransaction::factory()->forNonMember()->create([
            'type' => 'cash_out', 'amount' => 3000, 'charge_amount' => 20, 'total_amount' => 2980,
            'status' => 'completed', 'transaction_date' => now(),
        ]);

        $role = Role::firstOrCreate(['name' => 'gap_reader', 'guard_name' => 'web']);
        $role->syncPermissions(['accounting:view']);
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);
        $this->actingAs($user);

        $response = $this->gaps()->assertOk();
        $this->assertSame([$here->id], array_keys($this->rowsOf($response, 'share_capital')));
        $this->assertSame([], $this->rowsOf($response, 'cash_out'), 'A walk-in row with no branch is shown to a branch user.');
        $this->assertSame(1, $response->json('meta.totals.count'));
        $this->assertSame('100.00', $response->json('meta.totals.by_kind.share_capital.amount'));
        $this->gaps(['branch_id' => $far->id])->assertForbidden();
        $this->gaps(['branch_id' => $this->branch->id])->assertOk();

        $user->givePermissionTo(BranchAccess::VIEW_ALL);
        $this->actingAs($user->fresh());

        $all = $this->gaps()->assertOk();
        $this->assertEqualsCanonicalizing([$here->id, $there->id], array_keys($this->rowsOf($all, 'share_capital')));
        $this->assertArrayHasKey($walkIn->id, $this->rowsOf($all, 'cash_out'));
    }

    /** A payment's branch is its loan's. */
    public function test_a_branch_user_sees_only_the_collections_of_their_branches_loans(): void
    {
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $here = $this->createReleasedLoan();
        $there = $this->createReleasedLoan();
        $there->borrower->update(['branch_id' => $far->id]);
        $there->update(['branch_id' => $far->id]);
        $herePayment = $this->pay($here);
        $therePayment = $this->pay($there->fresh());
        $this->seedChartOfAccounts();

        $role = Role::firstOrCreate(['name' => 'gap_reader', 'guard_name' => 'web']);
        $role->syncPermissions(['accounting:view']);
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);
        $this->actingAs($user);

        $response = $this->gaps(['kind' => 'collection'])->assertOk();

        $this->assertSame([$herePayment->id], array_keys($this->rowsOf($response, 'collection')));
        $this->assertSame(['count' => 1, 'amount' => '11800.00'], $response->json('meta.totals.by_kind.collection'));
        $this->gaps(['kind' => 'collection', 'branch_id' => $far->id])->assertForbidden();

        $this->actingAs($this->admin);
        $this->assertSame([$therePayment->id], array_keys($this->rowsOf($this->gaps(['kind' => 'collection', 'branch_id' => $far->id]), 'collection')));
    }

    // ── It only reads ──

    public function test_reading_the_report_writes_nothing(): void
    {
        $loan = $this->createReleasedLoan();
        $this->pay($loan);
        $this->seedChartOfAccounts();
        $this->poster()->loanRelease($loan, $this->admin->id);
        ShareCapitalLedger::factory()->create(['borrower_id' => $loan->borrower_id, 'credit' => 100, 'debit' => 0]);
        $before = $this->snapshot();

        $writes = $this->writesDuring(function (): void {
            $this->gaps()->assertOk();
            $this->gaps(['kind' => 'collection', 'page' => 2])->assertOk();
        });

        $this->assertSame([], $writes);
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame(1, AccountingJournal::count());
    }

    public function test_with_no_chart_the_report_says_so_and_lists_nothing(): void
    {
        $loan = $this->createReleasedLoan();
        $this->pay($loan);

        $response = $this->gaps()->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertFalse($response->json('meta.books_kept'));
        $this->assertStringContainsString('No chart of accounts', $response->json('meta.notice'));
        $this->assertSame(0, $response->json('meta.totals.count'));
        $this->assertSame(['count' => 0, 'amount' => '0.00'], $response->json('meta.totals.by_kind.collection'));
    }

    private function adjustment(int $loanId, string $type, string $status, ?float $amount): LoanAdjustment
    {
        return LoanAdjustment::factory()->create([
            'loan_id' => $loanId,
            'adjustment_type' => $type,
            'new_values' => $amount === null ? ['waive_all' => true] : ['adjustment_amount' => $amount],
            'status' => $status,
            'applied_at' => $status === 'applied' ? now() : null,
            'adjusted_by' => $this->admin->id,
        ]);
    }
}
