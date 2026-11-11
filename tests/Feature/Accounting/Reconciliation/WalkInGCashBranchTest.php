<?php

namespace Tests\Feature\Accounting\Reconciliation;

use App\Models\AccountingJournal;
use App\Models\AccountingReconciliation;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\GCashNonMember;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * A new walk-in GCash transaction records the branch where it is done, and
 * its journal posts there, so that branch's Cash and GCash reconciliations
 * count it (O4 review, 2026-11-11). The recording user's branch, or one they
 * pick when they have several; `branches:view_all` may pick any branch or none
 * (head office). Existing transactions and journals are unchanged, and walk-in
 * rows stay visible to every branch on the GCash list.
 */
class WalkInGCashBranchTest extends TestCase
{
    use PostsJournals, ReconciliationActionHelpers, SetupLendyPH;

    private Branch $far;

    private GCashNonMember $walkIn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $this->travelTo(Carbon::parse('2026-09-10 10:00:00'));

        $this->far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $this->walkIn = GCashNonMember::factory()->create();
        GCashTier::create(['min_amount' => 1, 'max_amount' => 5000, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);
    }

    /** A signed-in user in $branches holding gcash:transact and accounting:reconcile (and $extra). */
    private function teller(array $branches, array $extra = []): User
    {
        $role = Role::create(['name' => 'teller_'.substr(md5(microtime()), 0, 8), 'guard_name' => 'web']);
        $role->syncPermissions(['gcash:transact', 'gcash:view', 'accounting:reconcile', ...$extra]);

        $user = $branches === []
            ? User::factory()->create(['branch_id' => null])
            : User::factory()->inBranches(...$branches)->create();
        $user->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    /** @param  array<string, mixed>  $fields */
    private function walkInCashIn(array $fields = []): GCashTransaction
    {
        $id = $this->postJson('/api/gcash/transactions', $fields + [
            'gcash_non_member_id' => $this->walkIn->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ])->assertCreated()->json('data.id');

        return GCashTransaction::query()->findOrFail($id);
    }

    private function journalOf(GCashTransaction $tx): AccountingJournal
    {
        return AccountingJournal::query()
            ->where('postable_type', GCashTransaction::class)
            ->where('postable_id', $tx->id)
            ->sole();
    }

    public function test_a_branch_users_walk_in_cash_in_posts_in_their_branch_and_reconciles_there(): void
    {
        $this->teller([$this->branch]);

        $tx = $this->walkInCashIn();

        $this->assertSame($this->branch->id, $tx->branch_id);
        $this->assertSame($this->branch->id, $this->journalOf($tx)->branch_id);

        // ₱1,000.00 plus the ₱20.00 charge into 1010 Cash on Hand.
        $id = $this->postJson('/api/accounting/reconciliations', [
            'account_id' => $this->account('1010'),
            'branch_id' => $this->branch->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'statement_balance' => 102000,
            'lines' => [['date' => '2026-09-10', 'description' => 'Walk-in Cash In', 'amount' => 102000]],
        ])->assertCreated()->assertJsonPath('data.book_balance', 102000)->json('data.id');

        $this->postJson("/api/accounting/reconciliations/{$id}/match", ['accept_suggestions' => true])
            ->assertOk()
            ->assertJsonPath('data.is_reconciled', true);
        $this->assertNotNull($this->statementLine(AccountingReconciliation::query()->findOrFail($id))->matched_journal_line_id);
    }

    public function test_a_user_with_several_branches_picks_one_of_them(): void
    {
        $this->teller([$this->branch, Branch::factory()->create()]);

        $this->postJson('/api/gcash/transactions', [
            'gcash_non_member_id' => $this->walkIn->id, 'type' => 'cash_in', 'amount' => 1000,
        ])->assertUnprocessable()->assertJsonPath('errors.branch_id.0', 'Pick the branch where this walk-in transaction is done.');

        $this->postJson('/api/gcash/transactions', [
            'gcash_non_member_id' => $this->walkIn->id, 'type' => 'cash_in', 'amount' => 1000, 'branch_id' => $this->far->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $this->assertSame(0, GCashTransaction::query()->count());

        $tx = $this->walkInCashIn(['branch_id' => $this->branch->id]);
        $this->assertSame($this->branch->id, $this->journalOf($tx)->branch_id);
    }

    public function test_a_user_without_a_branch_cannot_record_a_walk_in_transaction(): void
    {
        $this->teller([]);

        foreach ([[], ['branch_id' => $this->branch->id]] as $extra) {
            $this->postJson('/api/gcash/transactions', $extra + [
                'gcash_non_member_id' => $this->walkIn->id, 'type' => 'cash_out', 'amount' => 1000,
            ])->assertUnprocessable()->assertJsonValidationErrors('branch_id');
        }

        $this->postJson('/api/gcash/transactions', [
            'gcash_non_member_id' => $this->walkIn->id, 'type' => 'cash_out', 'amount' => 1000,
        ])->assertJsonPath('errors.branch_id.0', 'You have no branch assigned, so you cannot record a walk-in transaction. Ask an administrator to assign you a branch.');

        $this->assertSame(0, GCashTransaction::query()->count());
    }

    public function test_view_all_may_pick_any_branch_or_none_for_head_office(): void
    {
        $this->teller([$this->branch], [BranchAccess::VIEW_ALL]);

        $far = $this->walkInCashIn(['branch_id' => $this->far->id]);
        $this->assertSame($this->far->id, $this->journalOf($far)->branch_id);

        $headOffice = $this->walkInCashIn(['amount' => 1200]);
        $this->assertNull($headOffice->branch_id);
        $this->assertNull($this->journalOf($headOffice)->branch_id);
    }

    public function test_a_pending_cash_in_posts_in_its_recorded_branch_when_paid(): void
    {
        $this->teller([$this->branch]);
        $tx = $this->walkInCashIn(['is_pending' => true]);

        $this->teller([$this->far]);
        $this->patchJson("/api/gcash/transactions/{$tx->id}/paid")->assertOk();

        $this->assertSame($this->branch->id, $this->journalOf($tx)->branch_id);
    }

    public function test_a_journal_with_no_branch_stays_an_all_branches_item(): void
    {
        $headOffice = $this->walkInCashIn();
        $this->assertNull($this->journalOf($headOffice)->branch_id);

        $payload = [
            'account_id' => $this->account('1010'),
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
            'statement_balance' => 0,
        ];

        $this->postJson('/api/accounting/reconciliations', [...$payload, 'branch_id' => $this->branch->id])
            ->assertCreated()
            ->assertJsonPath('data.book_balance', 0)
            ->assertJsonCount(0, 'data.lines');

        $this->postJson('/api/accounting/reconciliations', $payload)
            ->assertCreated()
            ->assertJsonPath('data.book_balance', 102000)
            ->assertJsonCount(1, 'data.lines');
    }

    public function test_a_members_transaction_keeps_the_members_branch(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->teller([$this->branch, $this->far]);

        $id = $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $member->id, 'type' => 'cash_in', 'amount' => 1000,
        ])->assertCreated()->json('data.id');
        $tx = GCashTransaction::query()->findOrFail($id);

        $this->assertNull($tx->branch_id);
        $this->assertSame($this->branch->id, $this->journalOf($tx)->branch_id);

        $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $member->id, 'type' => 'cash_in', 'amount' => 1100, 'branch_id' => $this->far->id,
        ])->assertUnprocessable()->assertJsonPath('errors.branch_id.0', "A member's transaction is in the member's branch. Leave the branch out.");
    }

    public function test_walk_in_rows_stay_visible_to_every_branch_on_the_list(): void
    {
        $this->teller([$this->branch]);
        $tx = $this->walkInCashIn();

        $this->teller([$this->far]);
        $ids = array_column($this->getJson('/api/gcash/transactions')->assertOk()->json('data'), 'id');
        $this->assertContains($tx->id, $ids);
    }
}
