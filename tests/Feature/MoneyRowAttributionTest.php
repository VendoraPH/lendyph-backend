<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\GCashNonMember;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\Accounting\AutomaticPoster;
use App\Services\BranchAttributionBackfill;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE3 (D4, decisions 6 and 16): a share capital row and a member's
 * GCash row keep the branch whose books they posted in. The branch is stamped
 * when the row is written, the by-branch report figures read it through one
 * helper, a member who moves branch takes no past row along, and
 * `branches:backfill-attribution` gives past rows only the branch their posted
 * journal proves.
 */
class MoneyRowAttributionTest extends TestCase
{
    use PostsJournals;
    use SetupLendyPH;

    private Branch $north;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->north = Branch::factory()->create(['name' => 'North Branch', 'code' => 'NTH']);
    }

    // ── Stamping at creation ─────────────────────────────────────────────

    public function test_a_share_capital_entry_is_stamped_with_the_branch_its_journal_posts_in(): void
    {
        $this->seedChartOfAccounts();
        $member = $this->memberOf($this->north);

        $row = $this->payIn($member, 1500);

        $this->assertSame($this->north->id, $row->branch_id);
        $this->assertSame($this->north->id, $this->journalBranch($row, 'share_capital'));
    }

    public function test_a_payments_build_up_row_is_stamped_with_the_loans_branch(): void
    {
        $this->seedChartOfAccounts();
        $loan = $this->createReleasedLoan();
        $loan->update(['scb_amount' => 500]);
        // The member's record says North; the loan, and so its collection
        // journal, is in the main branch.
        $loan->borrower->update(['branch_id' => $this->north->id]);

        $repaymentId = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => (float) $loan->amortizationSchedules->first()->total_due + 300,
            'method' => 'cash',
        ])->assertCreated()->json('data.id');

        $row = ShareCapitalLedger::query()->where('repayment_id', $repaymentId)->firstOrFail();

        $this->assertSame($this->branch->id, $row->branch_id);
        $this->assertSame($this->branch->id, DB::table('accounting_journals')
            ->where('postable_type', 'App\Models\Repayment')->where('postable_id', $repaymentId)
            ->where('source', 'loan_collection')->value('branch_id'));
    }

    public function test_release_and_savings_rows_take_the_loans_and_the_savings_accounts_branch(): void
    {
        $member = $this->memberOf($this->north);
        $loan = $this->createReleasedLoan();
        $account = SavingsAccount::factory()->create(['borrower_id' => $member->id, 'branch_id' => $this->branch->id]);
        $savingsRow = SavingsTransaction::factory()->create(['account_id' => $account->id, 'type' => 'share_capital_transfer', 'debit' => '100.00', 'credit' => '0.00']);

        $atRelease = ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'loan_id' => $loan->id, 'credit' => 200, 'debit' => 0]);
        $fromSavings = ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'savings_transaction_id' => $savingsRow->id, 'credit' => 100, 'debit' => 0]);

        $this->assertSame($loan->branch_id, $atRelease->fresh()->branch_id);
        $this->assertSame($this->branch->id, $fromSavings->fresh()->branch_id);
    }

    public function test_a_branch_given_on_the_row_is_kept(): void
    {
        $row = ShareCapitalLedger::factory()->create([
            'borrower_id' => $this->memberOf($this->branch)->id,
            'branch_id' => $this->north->id,
        ]);

        $this->assertSame($this->north->id, $row->fresh()->branch_id);
    }

    public function test_a_members_gcash_row_is_stamped_with_the_members_branch_and_posts_there(): void
    {
        $this->seedChartOfAccounts();
        $this->seedTiers();
        $member = $this->memberOf($this->north);

        $id = $this->postJson('/api/gcash/transactions', ['borrower_id' => $member->id, 'type' => 'cash_in', 'amount' => 1000])
            ->assertCreated()->json('data.id');

        $this->assertSame($this->north->id, GCashTransaction::findOrFail($id)->branch_id);
        $this->assertSame($this->north->id, DB::table('accounting_journals')
            ->where('postable_type', 'App\Models\GCashTransaction')->where('postable_id', $id)->value('branch_id'));
    }

    public function test_a_walk_ins_branch_is_left_as_recorded(): void
    {
        $person = GCashNonMember::factory()->create();

        $headOffice = GCashTransaction::factory()->forNonMember($person)->create(['branch_id' => null, 'transactor_user_id' => $this->admin->id]);
        $north = GCashTransaction::factory()->forNonMember($person)->create(['branch_id' => $this->north->id, 'transactor_user_id' => $this->admin->id]);

        $this->assertNull($headOffice->fresh()->branch_id);
        $this->assertSame($this->north->id, $north->fresh()->branch_id);
    }

    public function test_a_pending_cash_in_posts_in_its_stamped_branch_after_the_member_moves(): void
    {
        $this->seedChartOfAccounts();
        $member = $this->memberOf($this->north);
        $tx = GCashTransaction::factory()->create([
            'borrower_id' => $member->id, 'status' => 'pending', 'transactor_user_id' => $this->admin->id,
        ]);
        $member->update(['branch_id' => $this->branch->id]);

        $this->patchJson("/api/gcash/transactions/{$tx->id}/paid")->assertOk();

        $this->assertSame($this->north->id, DB::table('accounting_journals')
            ->where('postable_type', 'App\Models\GCashTransaction')->where('postable_id', $tx->id)->value('branch_id'));
    }

    // ── Reports read the stamped branch ──────────────────────────────────

    public function test_by_branch_share_capital_equals_the_books_by_branch_for_new_rows(): void
    {
        $this->seedChartOfAccounts();
        $here = $this->memberOf($this->branch);
        $there = $this->memberOf($this->north);
        $this->payIn($here, 4000);
        $this->payIn($there, 1000);
        $this->payIn($there, 250, 'debit');

        $today = Carbon::today()->toDateString();
        $books = $this->booksShareCapitalByBranch();
        $this->assertEqualsWithDelta(4000, $books[$this->branch->id], 0.001);
        $this->assertEqualsWithDelta(750, $books[$this->north->id], 0.001);

        $cashFlow = $this->getJson("/api/reports/cash-flow?date_from={$today}&date_to={$today}")->assertOk()->json('data');
        foreach ([$this->branch->id, $this->north->id] as $branchId) {
            $row = collect($cashFlow['by_branch'])->firstWhere('branch_id', $branchId);
            $this->assertEqualsWithDelta($books[$branchId], $row['inflow_share_capital'] - $row['outflow_share_capital'], 0.001);

            $report = $this->getJson("/api/reports/share-capital?date_from={$today}&date_to={$today}&branch_id={$branchId}")->assertOk()->json('data');
            $this->assertEqualsWithDelta($books[$branchId], $report['net_movement'], 0.001);
            $this->assertSame('posted_branch', $report['branch_scope']);

            $filtered = $this->getJson("/api/reports/cash-flow?date_from={$today}&date_to={$today}&branch_id={$branchId}")->assertOk()->json('data');
            $this->assertEqualsWithDelta($books[$branchId], $filtered['share_capital']['net_movement'], 0.001);
        }
    }

    public function test_a_member_who_moves_branch_takes_no_past_row_along(): void
    {
        $member = $this->memberOf($this->north);
        $yesterday = Carbon::yesterday()->toDateString();
        $today = Carbon::today()->toDateString();
        $this->ledgerEntry($member, $yesterday, credit: 1000);

        $member->update(['branch_id' => $this->branch->id]);
        $this->ledgerEntry($member, $today, credit: 300);

        $northReport = $this->getJson("/api/reports/share-capital?date_from={$yesterday}&date_to={$today}&branch_id={$this->north->id}")->assertOk()->json('data');
        $mainReport = $this->getJson("/api/reports/share-capital?date_from={$yesterday}&date_to={$today}&branch_id={$this->branch->id}")->assertOk()->json('data');
        $this->assertEqualsWithDelta(1000, $northReport['credits'], 0.001);
        $this->assertEqualsWithDelta(1000, $northReport['by_member'][0]['closing_balance'], 0.001);
        $this->assertEqualsWithDelta(300, $mainReport['credits'], 0.001);
        $this->assertEqualsWithDelta(1000, array_sum(array_column($northReport['by_month'], 'credits')), 0.001);

        $cashFlow = $this->getJson("/api/reports/cash-flow?date_from={$yesterday}&date_to={$today}")->assertOk()->json('data');
        $this->assertEqualsWithDelta(1000, collect($cashFlow['by_branch'])->firstWhere('branch_id', $this->north->id)['inflow_share_capital'], 0.001);
        $this->assertEqualsWithDelta(300, collect($cashFlow['by_branch'])->firstWhere('branch_id', $this->branch->id)['inflow_share_capital'], 0.001);
    }

    public function test_a_row_with_no_branch_yet_counts_in_the_members_branch(): void
    {
        $member = $this->memberOf($this->north);
        $today = Carbon::today()->toDateString();
        $row = $this->ledgerEntry($member, $today, credit: 700);
        DB::table('share_capital_ledger')->where('id', $row->id)->update(['branch_id' => null]);

        $report = $this->getJson("/api/reports/share-capital?date_from={$today}&date_to={$today}&branch_id={$this->north->id}")->assertOk()->json('data');
        $cashFlow = $this->getJson("/api/reports/cash-flow?date_from={$today}&date_to={$today}")->assertOk()->json('data');

        $this->assertEqualsWithDelta(700, $report['credits'], 0.001);
        $this->assertEqualsWithDelta(700, collect($cashFlow['by_branch'])->firstWhere('branch_id', $this->north->id)['inflow_share_capital'], 0.001);
    }

    public function test_a_members_gcash_row_stays_with_the_branch_it_posted_in_after_a_move(): void
    {
        $member = $this->memberOf($this->north);
        $tx = GCashTransaction::factory()->create(['borrower_id' => $member->id, 'transactor_user_id' => $this->admin->id]);
        $member->update(['branch_id' => $this->branch->id]);

        $this->actingAs($this->branchUser($this->north));
        $this->assertContains($tx->id, array_column($this->getJson('/api/gcash/transactions?per_page=100')->assertOk()->json('data'), 'id'));

        $this->actingAs($this->branchUser($this->branch));
        $this->assertNotContains($tx->id, array_column($this->getJson('/api/gcash/transactions?per_page=100')->assertOk()->json('data'), 'id'));
    }

    // ── The backfill ─────────────────────────────────────────────────────

    public function test_the_backfill_sets_the_branch_its_posted_journal_proves_and_is_idempotent(): void
    {
        $this->seedChartOfAccounts();
        $member = $this->memberOf($this->north);
        $row = $this->payIn($member, 900);
        $tx = $this->postedMemberGCash($member);
        DB::table('share_capital_ledger')->where('id', $row->id)->update(['branch_id' => null]);
        DB::table('gcash_transactions')->where('id', $tx->id)->update(['branch_id' => null]);
        $member->update(['branch_id' => $this->branch->id]);

        $preview = app(BranchAttributionBackfill::class)->run(true);
        $this->assertSame([$row->id], array_column($preview['share_capital']['updated'], 'id'));
        $this->assertSame([$tx->id], array_column($preview['gcash']['updated'], 'id'));
        $this->assertNull($row->fresh()->branch_id);
        $this->assertNull($tx->fresh()->branch_id);
        $this->assertSame(0, AuditLog::query()->where('action', BranchAttributionBackfill::AUDIT_ACTION)->count());

        $result = app(BranchAttributionBackfill::class)->run(false);

        $this->assertCount(1, $result['share_capital']['updated']);
        $this->assertSame($this->north->id, $row->fresh()->branch_id);
        $this->assertSame($this->north->id, $tx->fresh()->branch_id);

        $audit = AuditLog::query()->where('action', BranchAttributionBackfill::AUDIT_ACTION)->where('auditable_id', $row->id)
            ->where('auditable_type', (new ShareCapitalLedger)->getMorphClass())->firstOrFail();
        $this->assertNull($audit->user_id);
        $this->assertSame(['branch_id' => null], $audit->old_values);
        $this->assertSame(['branch_id' => $this->north->id], $audit->new_values);
        $this->assertStringStartsWith('System correction:', $audit->description);

        $again = app(BranchAttributionBackfill::class)->run(false);
        $this->assertSame([], $again['share_capital']['updated']);
        $this->assertSame([], $again['gcash']['updated']);
        $this->assertSame(2, AuditLog::query()->where('action', BranchAttributionBackfill::AUDIT_ACTION)->count());
    }

    public function test_the_backfill_takes_a_build_up_rows_branch_from_its_payments_journal(): void
    {
        $this->seedChartOfAccounts();
        $loan = $this->createReleasedLoan();
        $loan->update(['scb_amount' => 500]);
        $repaymentId = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => (float) $loan->amortizationSchedules->first()->total_due + 300,
            'method' => 'cash',
        ])->assertCreated()->json('data.id');
        $row = ShareCapitalLedger::query()->where('repayment_id', $repaymentId)->firstOrFail();
        DB::table('share_capital_ledger')->where('id', $row->id)->update(['branch_id' => null]);
        $loan->borrower->update(['branch_id' => $this->north->id]);

        app(BranchAttributionBackfill::class)->run(false);

        $this->assertSame($loan->branch_id, $row->fresh()->branch_id);
    }

    public function test_the_backfill_writes_nothing_it_cannot_prove_and_lists_it(): void
    {
        $member = $this->memberOf($this->north);
        // No books were kept when these were written: no journal proves a branch.
        $unposted = $this->ledgerEntry($member, Carbon::today()->toDateString(), credit: 500);
        $unpostedTx = GCashTransaction::factory()->create(['borrower_id' => $member->id, 'transactor_user_id' => $this->admin->id]);

        $this->seedChartOfAccounts();
        // A journal that names no branch proves nothing either.
        $noBranchJournal = $this->payIn($this->memberOf($this->north), 400);
        DB::table('accounting_journals')->where('postable_type', 'App\Models\ShareCapitalLedger')
            ->where('postable_id', $noBranchJournal->id)->update(['branch_id' => null]);

        DB::table('share_capital_ledger')->update(['branch_id' => null]);
        DB::table('gcash_transactions')->update(['branch_id' => null]);
        $walkIn = GCashTransaction::factory()->forNonMember(GCashNonMember::factory()->create())->create(['branch_id' => null, 'transactor_user_id' => $this->admin->id]);

        $result = app(BranchAttributionBackfill::class)->run(false);

        $this->assertSame([], $result['share_capital']['updated']);
        $this->assertSame([], $result['gcash']['updated']);
        $this->assertEqualsCanonicalizing([$unposted->id, $noBranchJournal->id], array_column($result['share_capital']['skipped'], 'id'));
        $this->assertSame([$unpostedTx->id], array_column($result['gcash']['skipped'], 'id'));
        $this->assertNull($unposted->fresh()->branch_id);
        $this->assertNull($noBranchJournal->fresh()->branch_id);
        $this->assertNull($walkIn->fresh()->branch_id);
        $this->assertSame(0, AuditLog::query()->where('action', BranchAttributionBackfill::AUDIT_ACTION)->count());
    }

    public function test_the_commands_preview_and_report_without_writing(): void
    {
        $member = $this->memberOf($this->north);
        $row = $this->ledgerEntry($member, Carbon::today()->toDateString(), credit: 500);
        DB::table('share_capital_ledger')->where('id', $row->id)->update(['branch_id' => null]);
        $branchless = Borrower::factory()->create(['branch_id' => null]);

        $this->assertSame(0, Artisan::call('branches:backfill-attribution', ['--dry-run' => true]));
        $preview = Artisan::output();
        $this->assertStringContainsString($row->reference, $preview);
        $this->assertStringContainsString('no posted journal', $preview);

        $this->assertSame(0, Artisan::call('branches:attribution-report'));
        $report = Artisan::output();
        $this->assertStringContainsString($row->reference, $report);
        $this->assertStringContainsString($branchless->borrower_code, $report);

        $this->assertNull($row->fresh()->branch_id);
        $this->assertSame(0, AuditLog::query()->where('action', BranchAttributionBackfill::AUDIT_ACTION)->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function memberOf(Branch $branch): Borrower
    {
        return Borrower::factory()->create(['branch_id' => $branch->id]);
    }

    private function payIn(Borrower $member, float $amount, string $type = 'credit'): ShareCapitalLedger
    {
        $id = $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $member->id,
            'date' => now()->toDateString(),
            'description' => 'Share capital',
            'type' => $type,
            'amount' => $amount,
        ])->assertCreated()->json('data.id');

        return ShareCapitalLedger::findOrFail($id);
    }

    private function ledgerEntry(Borrower $member, string $date, float $credit = 0, float $debit = 0): ShareCapitalLedger
    {
        return ShareCapitalLedger::factory()->create([
            'borrower_id' => $member->id,
            'date' => $date,
            'credit' => $credit,
            'debit' => $debit,
            'created_by' => $this->admin->id,
            'description' => 'Share capital contribution',
        ]);
    }

    private function postedMemberGCash(Borrower $member): GCashTransaction
    {
        $tx = GCashTransaction::factory()->create([
            'borrower_id' => $member->id, 'paid_at' => now(), 'transactor_user_id' => $this->admin->id,
        ]);
        app(AutomaticPoster::class)->gcashTransaction($tx, $this->admin->id);

        return $tx;
    }

    private function journalBranch(ShareCapitalLedger $row, string $source): ?int
    {
        return DB::table('accounting_journals')
            ->where('postable_type', $row->getMorphClass())->where('postable_id', $row->id)
            ->where('source', $source)->value('branch_id');
    }

    /** @return array<int, float> share capital (3060) credit less debit in pesos, by journal branch */
    private function booksShareCapitalByBranch(): array
    {
        return DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->join('accounting_accounts as a', 'a.id', '=', 'l.accounting_account_id')
            ->where('a.code', '3060')
            ->where('j.status', 'posted')
            ->groupBy('j.branch_id')
            ->selectRaw('j.branch_id, SUM(l.credit) - SUM(l.debit) as net')
            ->pluck('net', 'branch_id')
            ->map(fn ($centavos) => ((int) $centavos) / 100)
            ->all();
    }

    private function seedTiers(): void
    {
        GCashTier::create(['min_amount' => 1, 'max_amount' => 1500, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);
    }

    private function branchUser(Branch $branch): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->branches()->sync([$branch->id]);
        $user->assignRole(Role::where('name', 'loan_officer')->firstOrFail());
        $user->givePermissionTo('gcash:view');

        return $user->fresh();
    }
}
