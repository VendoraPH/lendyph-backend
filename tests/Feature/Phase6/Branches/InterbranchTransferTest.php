<?php

namespace Tests\Feature\Phase6\Branches;

use App\Models\AccountingJournal;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\InterbranchTransfer;
use App\Models\User;
use App\Services\Accounting\TrialBalanceBuilder;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE5 (multi-branch decision 9): money moved between branches
 * goes through 1190 Interbranch Clearing. The maker never approves; approval
 * posts Dr 1190 / Cr the money account in the source branch; receipt posts Dr
 * the money account / Cr 1190 in the destination branch; a reversal posts
 * linked reversing journals. Money posts take an Idempotency-Key.
 */
class InterbranchTransferTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    private Branch $north;

    private User $maker;

    private User $approver;

    private User $receiver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
        $this->north = Branch::factory()->create(['name' => 'North Branch', 'code' => 'NOR']);

        $this->maker = User::factory()->inBranches($this->branch)->create(['first_name' => 'Mia', 'last_name' => 'Santos']);
        $this->maker->givePermissionTo(['interbranch_transfers:view', 'interbranch_transfers:create', 'interbranch_transfers:approve']);
        $this->approver = User::factory()->inBranches($this->branch)->create(['first_name' => 'Ana', 'last_name' => 'Cruz']);
        $this->approver->givePermissionTo(['interbranch_transfers:view', 'interbranch_transfers:approve']);
        $this->receiver = User::factory()->inBranches($this->north)->create(['first_name' => 'Rey', 'last_name' => 'Lim']);
        $this->receiver->givePermissionTo(['interbranch_transfers:view', 'interbranch_transfers:receive']);

        // ₱5,000.00 of cash in the source branch's books.
        $this->postSimpleJournal('1010', '3050', 500_000, ['branch_id' => $this->branch->id, 'date' => now()->toDateString()]);
    }

    public function test_the_maker_cannot_approve_even_with_the_permission(): void
    {
        $id = $this->create()->json('data.id');

        $this->actingAs($this->maker);
        $this->money("/api/interbranch-transfers/{$id}/approve")
            ->assertForbidden()
            ->assertJsonPath('message', 'You made this transfer, so someone else has to approve it.');

        $this->assertSame('pending_approval', InterbranchTransfer::find($id)->status);
        $this->assertSame(0, $this->transferJournals()->count());
    }

    public function test_the_maker_cannot_approve_as_super_admin(): void
    {
        $this->actingAs($this->admin);
        $id = $this->postJson('/api/interbranch-transfers', $this->payload())->assertCreated()->json('data.id');

        $this->money("/api/interbranch-transfers/{$id}/approve")
            ->assertForbidden()
            ->assertJsonPath('message', 'You made this transfer, so someone else has to approve it.');
        $this->assertSame('pending_approval', InterbranchTransfer::find($id)->status);
    }

    public function test_approval_posts_the_source_leg_and_receipt_posts_the_destination_leg(): void
    {
        $created = $this->create()
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.status_label', 'Pending approval')
            ->assertJsonPath('data.amount', 120_000)
            ->assertJsonPath('data.source_branch.name', $this->branch->name)
            ->assertJsonPath('data.destination_branch.name', 'North Branch')
            ->assertJsonPath('data.actions.can_approve', false)
            ->assertJsonPath('warnings', []);
        $id = $created->json('data.id');
        $this->assertSame('IBT-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT), $created->json('data.transfer_number'));

        $this->actingAs($this->approver);
        $this->getJson("/api/interbranch-transfers/{$id}")->assertOk()->assertJsonPath('data.actions.can_approve', true);
        $approved = $this->money("/api/interbranch-transfers/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_transit')
            ->assertJsonPath('data.status_label', 'In transit')
            ->assertJsonPath('data.approved_by.full_name', 'Ana Cruz');

        $dispatch = AccountingJournal::with('lines.account')->findOrFail($approved->json('data.dispatch_journal.id'));
        $this->assertSame('posted', $dispatch->status);
        $this->assertSame('transfer', $dispatch->source);
        $this->assertSame($this->branch->id, $dispatch->branch_id);
        $this->assertSame(now()->toDateString(), $dispatch->date->toDateString());
        $this->assertSame($approved->json('data.transfer_number'), $dispatch->reference);
        $this->assertSame([['1190', 120_000, 0], ['1010', 0, 120_000]], $this->linesOf($dispatch));

        $this->actingAs($this->receiver);
        $received = $this->money("/api/interbranch-transfers/{$id}/receive")
            ->assertOk()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.received_by.full_name', 'Rey Lim');

        $receipt = AccountingJournal::with('lines.account')->findOrFail($received->json('data.receipt_journal.id'));
        $this->assertSame($this->north->id, $receipt->branch_id);
        $this->assertSame([['1040', 120_000, 0], ['1190', 0, 120_000]], $this->linesOf($receipt));

        $actions = AuditLog::where('auditable_type', InterbranchTransfer::class)->where('auditable_id', $id)->orderBy('id')->pluck('action')->all();
        $this->assertSame(['interbranch_transfer_created', 'interbranch_transfer_approved', 'interbranch_transfer_received'], $actions);
    }

    public function test_receipt_needs_the_destination_branch_and_the_receive_permission(): void
    {
        $id = $this->createAndApprove();

        // The approver sees the source only, and holds no :receive.
        $this->actingAs($this->approver);
        $this->money("/api/interbranch-transfers/{$id}/receive")->assertForbidden();

        $sourceReceiver = User::factory()->inBranches($this->branch)->create();
        $sourceReceiver->givePermissionTo(['interbranch_transfers:view', 'interbranch_transfers:receive']);
        $this->actingAs($sourceReceiver);
        $this->money("/api/interbranch-transfers/{$id}/receive")
            ->assertForbidden()
            ->assertJsonPath('message', 'Receiving posts in North Branch\'s books, so it needs access to that branch.');

        $this->assertSame('in_transit', InterbranchTransfer::find($id)->status);
    }

    public function test_a_retry_with_the_same_key_posts_once(): void
    {
        $id = $this->create()->json('data.id');
        $key = (string) Str::uuid();

        $this->actingAs($this->approver);
        $first = $this->money("/api/interbranch-transfers/{$id}/approve", [], $key)->assertOk();
        $this->money("/api/interbranch-transfers/{$id}/approve", [], $key)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.dispatch_journal.id', $first->json('data.dispatch_journal.id'));

        $this->assertSame(1, $this->transferJournals()->count());

        // A new key is a new request, refused by the transfer's own state.
        $this->money("/api/interbranch-transfers/{$id}/approve")->assertStatus(409);
        $this->assertSame(1, $this->transferJournals()->count());
    }

    public function test_money_posts_need_an_idempotency_key(): void
    {
        $id = $this->create()->json('data.id');

        $this->actingAs($this->approver);
        $this->postJson("/api/interbranch-transfers/{$id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_a_closed_period_is_refused_and_nothing_changes(): void
    {
        $id = $this->create()->json('data.id');
        AccountingPeriod::create([
            'code' => now()->format('Y-m'),
            'name' => now()->format('F Y'),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'status' => 'closed',
            'closed_by' => $this->admin->id,
            'closed_at' => now(),
        ]);

        $this->actingAs($this->approver);
        $this->money("/api/interbranch-transfers/{$id}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');

        $this->assertSame('pending_approval', InterbranchTransfer::find($id)->status);
        $this->assertSame(0, $this->transferJournals()->count());
    }

    public function test_reversing_a_received_transfer_posts_linked_reversals_of_both_legs(): void
    {
        $id = $this->createAndApprove();
        $this->actingAs($this->receiver);
        $this->money("/api/interbranch-transfers/{$id}/receive")->assertOk();

        $both = User::factory()->inBranches($this->branch, $this->north)->create();
        $both->givePermissionTo(['interbranch_transfers:view', 'interbranch_transfers:approve']);
        $this->actingAs($both);
        $reversed = $this->money("/api/interbranch-transfers/{$id}/reverse", ['reason' => 'Sent to the wrong branch'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed')
            ->assertJsonPath('data.reversal_reason', 'Sent to the wrong branch');

        $transfer = InterbranchTransfer::find($id);
        foreach ([[$transfer->dispatch_journal_id, $transfer->dispatch_reversal_journal_id], [$transfer->receipt_journal_id, $transfer->receipt_reversal_journal_id]] as [$original, $mirror]) {
            $this->assertSame('reversed', AccountingJournal::find($original)->status);
            $this->assertSame($mirror, AccountingJournal::find($original)->reversed_by_journal_id);
            $this->assertSame($original, AccountingJournal::find($mirror)->reverses_journal_id);
        }
        $this->assertSame($reversed->json('data.dispatch_reversal_journal.id'), $transfer->dispatch_reversal_journal_id);

        // Every branch's 1190 and money accounts are back where they were.
        foreach ([$this->branch->id, $this->north->id] as $branchId) {
            $this->assertSame(0, $this->balance('1190', $branchId));
        }
        $this->assertSame(500_000, $this->balance('1010', $this->branch->id));
        $this->assertSame(0, $this->balance('1040', $this->north->id));
    }

    public function test_reversing_needs_both_branches_once_received_and_never_twice(): void
    {
        $id = $this->createAndApprove();

        $this->actingAs($this->approver);
        $this->money("/api/interbranch-transfers/{$id}/reverse", ['reason' => 'Never left the vault'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed')
            ->assertJsonPath('data.receipt_reversal_journal', null);
        $this->assertSame(0, $this->balance('1190', $this->branch->id));

        $this->money("/api/interbranch-transfers/{$id}/reverse", ['reason' => 'Again'])->assertStatus(409);

        $id = $this->createAndApprove();
        $this->actingAs($this->receiver);
        $this->money("/api/interbranch-transfers/{$id}/receive")->assertOk();
        $this->actingAs($this->approver);
        $this->money("/api/interbranch-transfers/{$id}/reverse", ['reason' => 'x'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Reversing a received transfer posts in North Branch\'s books too, so it needs access to that branch.');
    }

    public function test_the_journal_screen_will_not_reverse_a_transfer_leg(): void
    {
        $id = $this->createAndApprove();
        $journalId = InterbranchTransfer::find($id)->dispatch_journal_id;

        $this->actingAs($this->admin);
        $this->getJson("/api/accounting/journals/{$journalId}")
            ->assertOk()
            ->assertJsonPath('data.reversible', false);
        $this->postJson("/api/accounting/journals/{$journalId}/reverse")
            ->assertUnprocessable()
            ->assertJsonPath('errors.journal.0', 'This entry comes from an interbranch transfer. Reverse the transfer instead, so the transfer and the books stay the same.');
    }

    public function test_a_transfer_is_seen_only_by_its_two_branches(): void
    {
        $south = Branch::factory()->create(['name' => 'South Branch', 'code' => 'SOU']);
        $id = $this->create()->json('data.id');

        $outsider = User::factory()->inBranches($south)->create();
        $outsider->givePermissionTo(['interbranch_transfers:view', 'interbranch_transfers:approve', 'interbranch_transfers:receive']);
        $this->actingAs($outsider);
        $this->getJson("/api/interbranch-transfers/{$id}")->assertNotFound();
        $this->money("/api/interbranch-transfers/{$id}/approve")->assertNotFound();
        $this->getJson('/api/interbranch-transfers')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->receiver);
        $this->getJson("/api/interbranch-transfers/{$id}")->assertOk();
        $this->getJson('/api/interbranch-transfers')->assertOk()->assertJsonPath('data.0.id', $id);
        // The destination can't approve: approval posts in the source's books.
        $this->receiver->givePermissionTo('interbranch_transfers:approve');
        $this->money("/api/interbranch-transfers/{$id}/approve")
            ->assertForbidden()
            ->assertJsonPath('message', 'Approving posts in '.$this->branch->name.'\'s books, so it needs access to that branch.');
    }

    public function test_the_maker_must_see_the_source_branch(): void
    {
        $this->actingAs($this->receiver);
        $this->receiver->givePermissionTo('interbranch_transfers:create');

        $this->postJson('/api/interbranch-transfers', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_branch_id');
    }

    public function test_the_two_branches_and_accounts_are_checked(): void
    {
        $this->actingAs($this->maker);

        $this->postJson('/api/interbranch-transfers', $this->payload(['destination_branch_id' => $this->branch->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('destination_branch_id');
        $this->postJson('/api/interbranch-transfers', $this->payload(['from_account_id' => $this->account('4010')]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('from_account_id');
        $this->postJson('/api/interbranch-transfers', $this->payload(['amount' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->north->forceFill(['status' => Branch::STATUS_CLOSED])->saveQuietly();
        $this->postJson('/api/interbranch-transfers', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.destination_branch_id.0', 'North Branch is closed, so no money can move to or from it.');
    }

    public function test_a_source_going_below_zero_is_a_warning_never_a_refusal(): void
    {
        $warning = 'After this transfer, 1010 Cash on Hand reads -₱1,000.00 in '.$this->branch->name.'\'s books. '
            .'Opening balances are recorded with no branch, so a branch\'s own books can read below ₱0.00; check the money is there.';

        $id = $this->create(['amount' => 600_000])->assertJsonPath('warnings', [$warning])->json('data.id');

        $this->actingAs($this->approver);
        $this->money("/api/interbranch-transfers/{$id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'in_transit')
            ->assertJsonPath('warnings', [$warning]);
    }

    public function test_reject_and_cancel_close_a_pending_transfer_without_posting(): void
    {
        $id = $this->create()->json('data.id');
        $this->actingAs($this->maker);
        $this->postJson("/api/interbranch-transfers/{$id}/reject", ['note' => 'mine'])->assertForbidden();
        $this->postJson("/api/interbranch-transfers/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $id = $this->create()->json('data.id');
        $this->actingAs($this->approver);
        $this->postJson("/api/interbranch-transfers/{$id}/cancel")->assertForbidden();
        $this->postJson("/api/interbranch-transfers/{$id}/reject", ['note' => 'Not needed this week'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_note', 'Not needed this week');
        $this->money("/api/interbranch-transfers/{$id}/approve")->assertStatus(409);

        $this->assertSame(0, $this->transferJournals()->count());
    }

    public function test_ids_are_bounded_to_eighteen_digits(): void
    {
        $this->getJson('/api/interbranch-transfers/12345678901234567890')->assertNotFound();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return [
            'source_branch_id' => $this->branch->id,
            'destination_branch_id' => $this->north->id,
            'from_account_id' => $this->account('1010'),
            'to_account_id' => $this->account('1040'),
            'amount' => 120_000,
            'purpose' => 'Cash for Monday releases',
            'reference' => 'DS-0042',
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function create(array $overrides = []): TestResponse
    {
        $this->actingAs($this->maker);

        return $this->postJson('/api/interbranch-transfers', $this->payload($overrides))->assertCreated();
    }

    private function createAndApprove(): int
    {
        $id = $this->create()->json('data.id');
        $this->actingAs($this->approver);
        $this->money("/api/interbranch-transfers/{$id}/approve")->assertOk();

        return $id;
    }

    /** @param array<string, mixed> $body */
    private function money(string $uri, array $body = [], ?string $key = null): TestResponse
    {
        return $this->postJson($uri, $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function transferJournals()
    {
        return AccountingJournal::query()->where('source', 'transfer');
    }

    /** @return list<array{0: string, 1: int, 2: int}> */
    private function linesOf(AccountingJournal $journal): array
    {
        return $journal->lines->map(fn ($line) => [$line->account->code, (int) $line->debit, (int) $line->credit])->all();
    }

    private function balance(string $code, int $branchId): int
    {
        return (int) (app(TrialBalanceBuilder::class)->signedBalances(null, $branchId, [$this->account($code)])[$this->account($code)] ?? 0);
    }
}
