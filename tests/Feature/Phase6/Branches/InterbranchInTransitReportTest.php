<?php

namespace Tests\Feature\Phase6\Branches;

use App\Models\AccountingReconciliation;
use App\Models\Branch;
use App\Models\InterbranchTransfer;
use App\Models\User;
use App\Services\BranchAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE5: the in-transit report (each transfer with its age, and
 * per-branch 1190 balances that add up to the total), the §15 branch counts,
 * and the closure checklist's "transfers in transit" item.
 */
class InterbranchInTransitReportTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private Branch $north;

    private Branch $south;

    private User $checker;

    private User $receiver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $this->north = Branch::factory()->create(['name' => 'North Branch', 'code' => 'NOR']);
        $this->south = Branch::factory()->create(['name' => 'South Branch', 'code' => 'SOU']);
        $this->checker = User::factory()->inBranches($this->branch, $this->north, $this->south)->create();
        $this->checker->givePermissionTo(['interbranch_transfers:view', 'interbranch_transfers:approve', BranchAccess::VIEW_ALL]);
        // Never the approver: the receiver is a third person.
        $this->receiver = User::factory()->inBranches($this->branch, $this->north, $this->south)->create();
        $this->receiver->givePermissionTo(['interbranch_transfers:view', 'interbranch_transfers:receive', BranchAccess::VIEW_ALL]);
    }

    public function test_the_in_transit_totals_add_up(): void
    {
        Carbon::setTestNow(now()->subDays(3));
        $old = $this->sent($this->branch, $this->north, 100_000);
        Carbon::setTestNow();
        $this->sent($this->north, $this->south, 30_000);
        $received = $this->sent($this->branch, $this->south, 50_000);
        $this->actingAs($this->receiver);
        $this->money("/api/interbranch-transfers/{$received}/receive")->assertOk();

        $data = $this->getJson('/api/interbranch-transfers/in-transit')->assertOk()->json('data');

        $this->assertSame([$old, $data['transfers'][1]['id']], array_column($data['transfers'], 'id'));
        $this->assertSame(3, $data['transfers'][0]['age_days']);
        $this->assertSame(0, $data['transfers'][1]['age_days']);
        $this->assertSame(130_000, $data['total_in_transit']);
        $this->assertSame(['1190'], array_column($data['clearing_accounts'], 'code'));

        $balances = collect($data['branches'])->pluck('net_interbranch_position', 'branch_name')->all();
        $this->assertSame([
            $this->branch->name => 150_000,
            'North Branch' => 30_000,
            'South Branch' => -50_000,
        ], $balances);
        $this->assertSame(array_sum($balances), $data['total_clearing_balance']);
        // In transit: ₱1,000 + ₱300; received ₱500 nets out across branches.
        $this->assertSame(130_000, $data['total_clearing_balance']);
        $this->assertSame(0, $data['difference']);
    }

    public function test_a_branch_user_sees_only_their_branches(): void
    {
        $this->sent($this->branch, $this->north, 100_000);
        $this->sent($this->north, $this->south, 30_000);

        $southUser = User::factory()->inBranches($this->south)->create();
        $southUser->givePermissionTo('interbranch_transfers:view');
        $this->actingAs($southUser);

        $data = $this->getJson('/api/interbranch-transfers/in-transit')->assertOk()->json('data');
        $this->assertCount(1, $data['transfers']);
        $this->assertSame(['South Branch'], array_column($data['branches'], 'branch_name'));
        $this->assertSame(array_sum(array_column($data['branches'], 'net_interbranch_position')), $data['total_clearing_balance']);
        $this->assertNull($data['difference']);

        $nobody = User::factory()->inBranches($this->south)->create();
        $this->actingAs($nobody);
        $this->getJson('/api/interbranch-transfers/in-transit')->assertForbidden();
    }

    public function test_the_branch_counts_are_branch_scoped_and_need_their_permissions(): void
    {
        $pending = $this->pending($this->branch, $this->north);
        $this->pending($this->north, $this->south);
        $this->south->forceFill(['status' => Branch::STATUS_SUSPENDED])->saveQuietly();
        AccountingReconciliation::create([
            'accounting_account_id' => $this->account('1010'),
            'branch_id' => $this->north->id,
            'period' => now()->format('Y-m'),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'statement_balance' => 12_345,
        ]);
        // South's older month didn't reconcile, but its latest one did: only
        // the latest reconciliation of each branch and account counts.
        foreach ([[now()->subMonths(2), 99_999], [now()->subMonth(), 0]] as [$month, $statement]) {
            AccountingReconciliation::create([
                'accounting_account_id' => $this->account('1010'),
                'branch_id' => $this->south->id,
                'period' => $month->format('Y-m'),
                'start_date' => $month->copy()->startOfMonth()->toDateString(),
                'end_date' => $month->copy()->endOfMonth()->toDateString(),
                'statement_balance' => $statement,
            ]);
        }

        $this->actingAs($this->admin);
        $this->getJson('/api/branch-counts')->assertOk()->assertExactJson(['data' => [
            'pending_transfers' => 2,
            'in_transit_transfers' => 0,
            'suspended_branches' => 1,
            'unreconciled_branches' => 1,
        ]]);

        $mainUser = User::factory()->inBranches($this->branch)->create();
        $mainUser->givePermissionTo(['dashboard:analytics', 'interbranch_transfers:view']);
        $this->actingAs($mainUser);
        $this->getJson('/api/branch-counts')->assertOk()->assertExactJson(['data' => [
            'pending_transfers' => 1,
            'in_transit_transfers' => 0,
            'suspended_branches' => 0,
            'unreconciled_branches' => null,
        ]]);
        $this->assertSame('pending_approval', InterbranchTransfer::find($pending)->status);

        $mainUser->revokePermissionTo('dashboard:analytics');
        $this->getJson('/api/branch-counts')->assertForbidden();
    }

    public function test_the_closure_checklist_blocks_while_a_transfer_is_in_transit(): void
    {
        $this->checker->givePermissionTo('branches:manage');
        $id = $this->sent($this->branch, $this->south, 40_000);
        $this->pending($this->north, $this->south);

        $this->actingAs($this->admin);
        // Suspended first: an active branch's closure is refused before its checklist is read.
        $this->patchJson("/api/branches/{$this->south->id}/status", ['status' => 'suspended', 'reason' => 'Winding down'])->assertOk();
        $checklist = collect($this->getJson("/api/branches/{$this->south->id}/closure")->assertOk()->json('data.checklist'))->keyBy('key');
        $this->assertSame(['key' => 'transfers_in_transit', 'label' => 'Transfers in transit', 'count' => 1, 'amount' => null, 'clear' => false], $checklist['transfers_in_transit']);
        $this->assertSame(1, $checklist['pending_approvals']['count']);

        $this->postJson("/api/branches/{$this->south->id}/closure", ['effective_on' => now()->toDateString(), 'reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.branch.0', "This branch can't be closed yet: 1 pending approval or request, 1 transfer in transit and 2 assigned users.");

        $this->actingAs($this->receiver);
        $this->money("/api/interbranch-transfers/{$id}/receive")->assertOk();
        $this->actingAs($this->admin);
        $checklist = collect($this->getJson("/api/branches/{$this->south->id}/closure")->json('data.checklist'))->keyBy('key');
        $this->assertTrue($checklist['transfers_in_transit']['clear']);
    }

    private function pending(Branch $from, Branch $to, int $amount = 10_000): int
    {
        $maker = User::factory()->inBranches($from)->create();
        $maker->givePermissionTo(['interbranch_transfers:view', 'interbranch_transfers:create']);
        $this->actingAs($maker);

        return $this->postJson('/api/interbranch-transfers', [
            'source_branch_id' => $from->id,
            'destination_branch_id' => $to->id,
            'from_account_id' => $this->account('1010'),
            'to_account_id' => $this->account('1010'),
            'amount' => $amount,
            'purpose' => 'Float',
        ])->assertCreated()->json('data.id');
    }

    private function sent(Branch $from, Branch $to, int $amount): int
    {
        $id = $this->pending($from, $to, $amount);
        $this->actingAs($this->checker);
        $this->money("/api/interbranch-transfers/{$id}/approve")->assertOk();

        return $id;
    }

    private function money(string $uri): TestResponse
    {
        return $this->postJson($uri, [], ['Idempotency-Key' => (string) Str::uuid()]);
    }
}
