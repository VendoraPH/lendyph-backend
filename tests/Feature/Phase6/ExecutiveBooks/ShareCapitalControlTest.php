<?php

namespace Tests\Feature\Phase6\ExecutiveBooks;

use App\Models\AccountingOpeningBalance;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Role;
use App\Models\ShareCapitalLedger;
use App\Models\User;
use App\Services\Accounting\TrialBalanceBuilder;
use App\Services\ReportService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 EB-D2: the share capital subledger checked against the share
 * capital account (3060), by branch. A subledger row counts in the branch it
 * posted in (BranchAttribution), a journal line in its journal's branch.
 * Integer centavos; difference = books − subledger.
 */
class ShareCapitalControlTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private Branch $other;

    private Borrower $here;

    private Borrower $there;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        AccountingOpeningBalance::query()->create(['as_of' => '2026-08-31', 'created_by' => $this->admin->id])
            ->forceFill(['status' => AccountingOpeningBalance::STATUS_FINALISED, 'finalised_at' => now()])->save();
        $this->other = Branch::factory()->create(['name' => 'Tagum']);
        $this->here = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->there = Borrower::factory()->create(['branch_id' => $this->other->id]);

        // Main branch: ₱1,500.00 in the subledger, ₱1,500.00 in the books.
        $this->entry($this->here, 1000, 0, '2026-09-02');
        $this->entry($this->here, 500, 0, '2026-09-03');
        $this->postSimpleJournal('1010', '3060', 150000, ['date' => '2026-09-03']);

        // Tagum: ₱800.00 in the subledger (₱1,000.00 less ₱200.00), ₱1,000.00 in the books.
        $this->entry($this->there, 1000, 0, '2026-09-04');
        $this->entry($this->there, 0, 200, '2026-09-05');
        $this->postSimpleJournal('1010', '3060', 100000, ['date' => '2026-09-04', 'branch_id' => $this->other->id]);

        // A row stamped with the main branch for a Tagum member counts in the main branch.
        $this->entry($this->there, 250.25, 0, '2026-09-06', $this->branch->id);
        $this->postSimpleJournal('1010', '3060', 25025, ['date' => '2026-09-06']);
    }

    private function entry(Borrower $member, float $credit, float $debit, string $date, ?int $branchId = null): void
    {
        ShareCapitalLedger::create(array_filter([
            'borrower_id' => $member->id,
            'branch_id' => $branchId,
            'date' => $date,
            'description' => 'Share capital',
            'credit' => $credit,
            'debit' => $debit,
            'created_by' => $this->admin->id,
        ], fn ($value) => $value !== null));
    }

    /** @return array<string, mixed> */
    private function control(string $query = 'as_of=2026-09-30'): array
    {
        return $this->getJson('/api/dashboard/executive/share-capital-control?'.$query)->assertOk()->json('data');
    }

    public function test_each_branch_sets_the_subledger_against_the_books(): void
    {
        $control = $this->control();
        $rows = collect($control['rows'])->keyBy('branch_id');

        $this->assertSame('2026-09-30', $control['as_of']);
        $this->assertSame('3060', $control['account']['code']);
        $this->assertSame(
            ['branch_id' => $this->branch->id, 'branch_name' => $this->branch->name, 'subledger' => 175025, 'books' => 175025, 'difference' => 0, 'is_reconciled' => true],
            $rows[$this->branch->id],
        );
        $this->assertSame(
            ['branch_id' => $this->other->id, 'branch_name' => 'Tagum', 'subledger' => 80000, 'books' => 100000, 'difference' => 20000, 'is_reconciled' => false],
            $rows[$this->other->id],
        );
        $this->assertSame(['subledger' => 255025, 'books' => 275025, 'difference' => 20000, 'is_reconciled' => false], $control['totals']);
        $this->assertFalse($control['is_reconciled']);
    }

    public function test_the_subledger_equals_the_share_capital_report_and_the_books_the_trial_balance(): void
    {
        $rows = collect($this->control()['rows'])->keyBy('branch_id');
        $reports = app(ReportService::class);
        $trialBalance = app(TrialBalanceBuilder::class);

        foreach ([$this->branch->id, $this->other->id] as $branchId) {
            $report = $reports->shareCapital(['date_to' => '2026-09-30', 'branch_id' => $branchId, 'branch_ids' => null]);
            $this->assertSame((int) round($report['closing_balance'] * 100), $rows[$branchId]['subledger'], "branch {$branchId}");

            $line = collect($trialBalance->build('2026-09-30', $branchId)['rows'])->firstWhere('account_code', '3060');
            $this->assertSame((int) $line['credit'] - (int) $line['debit'], $rows[$branchId]['books'], "branch {$branchId}");
        }

        $whole = $reports->shareCapital(['date_to' => '2026-09-30', 'branch_ids' => null]);
        $this->assertSame((int) round($whole['closing_balance'] * 100), $this->control()['totals']['subledger']);
    }

    public function test_it_reads_the_position_on_the_as_of_date(): void
    {
        $rows = collect($this->control('as_of=2026-09-04')['rows'])->keyBy('branch_id');

        $this->assertSame(150000, $rows[$this->branch->id]['subledger']);
        $this->assertSame(100000, $rows[$this->other->id]['subledger']);
        $this->assertSame(100000, $rows[$this->other->id]['books']);
    }

    public function test_every_active_branch_has_a_row_and_journals_with_no_branch_have_their_own(): void
    {
        $idle = Branch::factory()->create(['name' => 'Panabo']);
        $this->postSimpleJournal('1010', '3060', 40000, ['date' => '2026-09-01', 'branch_id' => null, 'source' => 'opening_balance']);

        $rows = collect($this->control()['rows']);

        $this->assertSame(
            ['branch_id' => $idle->id, 'branch_name' => 'Panabo', 'subledger' => 0, 'books' => 0, 'difference' => 0, 'is_reconciled' => true],
            $rows->firstWhere('branch_id', $idle->id),
        );
        $this->assertSame(
            ['branch_id' => null, 'branch_name' => 'No branch', 'subledger' => 0, 'books' => 40000, 'difference' => 40000, 'is_reconciled' => false],
            $rows->last(),
        );
        $this->assertSame(315025, $this->control()['totals']['books']);
    }

    public function test_a_branch_user_sees_only_their_own_branches(): void
    {
        $this->postSimpleJournal('1010', '3060', 40000, ['date' => '2026-09-01', 'branch_id' => null]);
        $role = Role::create(['name' => 'ebd2_share_control', 'guard_name' => 'web']);
        $role->givePermissionTo(['dashboard:analytics', 'accounting:view', 'share_capital:view']);
        $user = User::factory()->inBranches($this->other)->create();
        $user->assignRole($role);
        $this->actingAs($user);

        $control = $this->control();

        $this->assertSame([$this->other->id], array_column($control['rows'], 'branch_id'), 'No other branch, and no "No branch" row.');
        $this->assertSame(['subledger' => 80000, 'books' => 100000, 'difference' => 20000, 'is_reconciled' => false], $control['totals']);
        $this->getJson("/api/dashboard/executive/share-capital-control?branch_id={$this->branch->id}")->assertForbidden();
    }

    public function test_a_branch_filter_narrows_it_to_that_branch(): void
    {
        $control = $this->control("as_of=2026-09-30&branch_id={$this->other->id}");

        $this->assertSame([$this->other->id], array_column($control['rows'], 'branch_id'));
    }

    public function test_it_needs_the_dashboard_the_books_and_share_capital(): void
    {
        foreach ([
            ['accounting:view', 'share_capital:view'],
            ['dashboard:analytics', 'share_capital:view'],
            ['dashboard:analytics', 'accounting:view'],
        ] as $index => $permissions) {
            $role = Role::create(['name' => "ebd2_share_control_{$index}", 'guard_name' => 'web']);
            $role->givePermissionTo($permissions);
            $user = User::factory()->inBranches($this->branch)->create();
            $user->assignRole($role);
            $this->actingAs($user);

            $this->getJson('/api/dashboard/executive/share-capital-control')->assertForbidden();
        }
    }

    public function test_until_the_books_are_ready_no_book_figure_is_sent(): void
    {
        AccountingOpeningBalance::query()->delete();

        $control = $this->control();

        $this->assertSame('requires_configuration', $control['books_state']['state']);
        $this->assertSame('3060', $control['account']['code']);
        foreach ([...$control['rows'], $control['totals']] as $row) {
            $this->assertNull($row['books']);
            $this->assertNull($row['difference']);
            $this->assertNull($row['is_reconciled'], 'A missing opening balance is not a difference.');
        }
        $this->assertNull($control['is_reconciled']);
        $this->assertSame(255025, $control['totals']['subledger']);
    }

    public function test_an_unmapped_account_reads_no_books_figure(): void
    {
        DB::table('accounting_account_mappings')->where('role', 'share_capital')->delete();

        $control = $this->control();

        $this->assertSame('ready', $control['books_state']['state']);
        $this->assertNull($control['account']);
        $this->assertNull($control['rows'][0]['books']);
        $this->assertNull($control['rows'][0]['difference']);
        $this->assertNull($control['rows'][0]['is_reconciled']);
        $this->assertNull($control['is_reconciled']);
        $this->assertSame(255025, $control['totals']['subledger']);
    }

    public function test_the_figures_are_cached_per_as_of_date(): void
    {
        $this->assertSame(175025, collect($this->control()['rows'])->firstWhere('branch_id', $this->branch->id)['books']);

        $this->postSimpleJournal('1010', '3060', 1000, ['date' => '2026-09-07']);

        $this->assertSame(175025, collect($this->control()['rows'])->firstWhere('branch_id', $this->branch->id)['books'], 'Cached for five minutes.');
        $this->assertSame(176025, collect($this->control('as_of=2026-09-29')['rows'])->firstWhere('branch_id', $this->branch->id)['books'], 'Another date is its own entry.');

        Cache::flush();
        $this->assertSame(176025, collect($this->control()['rows'])->firstWhere('branch_id', $this->branch->id)['books']);
    }
}
