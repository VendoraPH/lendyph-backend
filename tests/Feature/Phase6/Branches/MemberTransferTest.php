<?php

namespace Tests\Feature\Phase6\Branches;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\GCashTransaction;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\PortalRequest;
use App\Models\PortalUser;
use App\Models\SavingsAccount;
use App\Models\TimeDeposit;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Branches\MemberTransfer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 6 MB-BE4 (multi-branch decision 7): moving a member to another
 * branch is its own action, `POST /borrowers/{borrower}/transfer`, with a
 * reason and one audit row. It needs `borrowers:transfer` and both branches
 * in the caller's reach, and it's refused while the member has anything
 * still open in their branch. A plain edit only fills in a missing branch.
 */
class MemberTransferTest extends TestCase
{
    use SetupLendyPH;

    private Branch $second;

    private const REASON = 'Moved to Butuan for work.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->second = Branch::factory()->create(['name' => 'Second Branch', 'code' => 'SEC']);
    }

    // The transfer -------------------------------------------------------

    public function test_a_transfer_moves_the_member_and_writes_one_audit_row_naming_both_branches(): void
    {
        $member = $this->member();
        $before = AuditLog::query()->count();

        $this->postJson("/api/borrowers/{$member->id}/transfer", ['branch_id' => $this->second->id, 'reason' => self::REASON])
            ->assertOk()
            ->assertJsonPath('data.id', $member->id)
            ->assertJsonPath('data.branch.id', $this->second->id);

        $this->assertSame($this->second->id, (int) $member->fresh()->branch_id);
        $this->assertSame($before + 1, AuditLog::query()->count(), 'exactly one audit row');

        $row = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame('member_transferred', $row->action);
        $this->assertSame((new Borrower)->getMorphClass(), $row->auditable_type);
        $this->assertSame($member->id, (int) $row->auditable_id);
        $this->assertSame($this->admin->id, (int) $row->user_id);
        $this->assertSame(['branch_id' => $this->branch->id], $row->old_values);
        $this->assertSame(['branch_id' => $this->second->id], $row->new_values);
        $this->assertSame(
            sprintf(
                'Member %s (%s) was transferred from %s to "Second Branch" (SEC). Reason: %s',
                $member->full_name,
                $member->borrower_code,
                $this->branch->auditName(),
                self::REASON,
            ),
            $row->description,
        );
    }

    public function test_the_destination_and_a_reason_are_required(): void
    {
        $member = $this->member();

        $this->postJson("/api/borrowers/{$member->id}/transfer", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'branch_id' => 'Choose the branch to move this member to.',
                'reason' => 'Give a reason for the transfer.',
            ]);

        $this->postJson("/api/borrowers/{$member->id}/transfer", ['branch_id' => $this->second->id, 'reason' => str_repeat('a', 501)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason' => 'The reason can be at most 500 characters.']);
    }

    public function test_a_transfer_to_the_members_own_branch_is_refused(): void
    {
        $member = $this->member();

        $this->postJson("/api/borrowers/{$member->id}/transfer", ['branch_id' => $this->branch->id, 'reason' => self::REASON])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id' => 'This member is already in '.$this->branch->auditName().'.']);
    }

    public function test_a_member_with_no_branch_gets_one_by_editing_instead(): void
    {
        $member = AuditLogService::withoutModelAuditing(fn () => Borrower::factory()->create(['branch_id' => null, 'status' => 'active']));

        $this->postJson("/api/borrowers/{$member->id}/transfer", ['branch_id' => $this->second->id, 'reason' => self::REASON])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id' => 'This member has no branch yet. Assign one by editing the member.']);

        $this->assertNull($member->fresh()->branch_id);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function branchesThatCantReceive(): array
    {
        return [
            'suspended' => [Branch::STATUS_SUSPENDED, 'is suspended'],
            'closed' => [Branch::STATUS_CLOSED, 'is closed'],
        ];
    }

    #[DataProvider('branchesThatCantReceive')]
    public function test_a_suspended_or_closed_branch_cant_receive_a_member(string $status, string $words): void
    {
        $member = $this->member();
        $this->second->forceFill(['status' => $status])->saveQuietly();

        $this->postJson("/api/borrowers/{$member->id}/transfer", ['branch_id' => $this->second->id, 'reason' => self::REASON])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id' => "\"Second Branch\" (SEC) {$words}, so it can't receive members."]);

        $this->assertSame($this->branch->id, (int) $member->fresh()->branch_id);
    }

    // Who may transfer ----------------------------------------------------

    public function test_it_needs_the_transfer_permission(): void
    {
        $member = $this->member();
        $this->actingAs($this->staffIn([$this->branch, $this->second], ['borrowers:view', 'borrowers:update']));

        $this->postJson("/api/borrowers/{$member->id}/transfer", ['branch_id' => $this->second->id, 'reason' => self::REASON])
            ->assertForbidden()
            ->assertJsonPath('message', 'Transferring a member to another branch needs the member transfer and member view permissions.');

        $this->assertSame($this->branch->id, (int) $member->fresh()->branch_id);
    }

    public function test_the_transfer_permission_alone_cant_read_the_members_record(): void
    {
        $member = $this->member();
        $this->actingAs($this->staffIn([$this->branch, $this->second], ['borrowers:transfer']));

        $this->postJson("/api/borrowers/{$member->id}/transfer", ['branch_id' => $this->second->id, 'reason' => self::REASON])
            ->assertForbidden()
            ->assertJsonPath('message', 'Transferring a member to another branch needs the member transfer and member view permissions.');

        $this->assertSame($this->branch->id, (int) $member->fresh()->branch_id);
    }

    public function test_the_callers_reach_is_checked_again_on_the_members_branch_under_the_lock(): void
    {
        $member = $this->member();
        // The member moved to a branch this caller can't see after the route
        // bound it.
        $third = Branch::factory()->create(['name' => 'Third Branch', 'code' => 'THR']);
        $caller = $this->staffIn([$this->branch, $this->second], ['borrowers:transfer', 'borrowers:view']);
        $bound = $member->replicate()->forceFill(['id' => $member->id]);
        Borrower::query()->whereKey($member->id)->update(['branch_id' => $third->id]);

        try {
            app(MemberTransfer::class)->transfer($bound, $this->second->id, self::REASON, $caller);
            $this->fail('A member in a branch the caller can no longer see was transferred.');
        } catch (ModelNotFoundException) {
            // 404, as for a member the caller never saw.
        }

        $this->assertSame($third->id, (int) $member->fresh()->branch_id);
    }

    public function test_a_branch_user_holding_both_branches_may_transfer(): void
    {
        $member = $this->member();
        $this->actingAs($this->staffIn([$this->branch, $this->second], ['borrowers:transfer', 'borrowers:view']));

        $this->postJson("/api/borrowers/{$member->id}/transfer", ['branch_id' => $this->second->id, 'reason' => self::REASON])->assertOk();

        $this->assertSame($this->second->id, (int) $member->fresh()->branch_id);
    }

    public function test_a_member_in_a_branch_the_caller_cant_see_answers_404(): void
    {
        $member = $this->member();
        $this->actingAs($this->staffIn([$this->second], ['borrowers:transfer', 'borrowers:view']));

        $this->postJson("/api/borrowers/{$member->id}/transfer", ['branch_id' => $this->second->id, 'reason' => self::REASON])
            ->assertNotFound();

        $this->assertSame($this->branch->id, (int) $member->fresh()->branch_id);
    }

    public function test_a_destination_the_caller_cant_see_reads_like_a_missing_one(): void
    {
        $member = $this->member();
        $this->actingAs($this->staffIn([$this->branch], ['borrowers:transfer', 'borrowers:view']));

        foreach ([$this->second->id, 999_999] as $branchId) {
            $this->postJson("/api/borrowers/{$member->id}/transfer", ['branch_id' => $branchId, 'reason' => self::REASON])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['branch_id' => 'Choose one of your branches.']);
        }

        $this->assertSame($this->branch->id, (int) $member->fresh()->branch_id);
    }

    // Refusals while something is open -------------------------------------

    /** @return array<string, array{0: string, 1: string}> */
    public static function openRecords(): array
    {
        return [
            'a draft loan' => ['loan:draft', '1 loan application not yet released'],
            'a loan for approval' => ['loan:for_review', '1 loan application not yet released'],
            'an approved loan' => ['loan:approved', '1 loan application not yet released'],
            'a released loan' => ['loan:released', '1 loan still being paid'],
            'an ongoing loan' => ['loan:ongoing', '1 loan still being paid'],
            'a defaulted loan' => ['loan:defaulted', '1 loan still being paid'],
            'an active savings account' => ['savings:active', '1 open savings account'],
            'a dormant savings account' => ['savings:dormant', '1 open savings account'],
            'a frozen savings account' => ['savings:frozen', '1 open savings account'],
            'an active time deposit' => ['deposit:active', '1 open time deposit'],
            'a matured time deposit' => ['deposit:matured', '1 open time deposit'],
            'a submitted portal request' => ['portal:submitted', '1 open portal request'],
            'a portal request under review' => ['portal:under_review', '1 open portal request'],
            'a portal request needing information' => ['portal:need_info', '1 open portal request'],
            'an approved portal request' => ['portal:approved', '1 open portal request'],
            'a pending GCash Cash In' => ['gcash:pending', '1 GCash Cash In not yet paid'],
        ];
    }

    #[DataProvider('openRecords')]
    public function test_each_open_record_refuses_the_transfer(string $record, string $words): void
    {
        $member = $this->member();
        $this->give($member, $record);
        $before = AuditLog::query()->count();

        $this->postJson("/api/borrowers/{$member->id}/transfer", ['branch_id' => $this->second->id, 'reason' => self::REASON])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id' => "This member can't be transferred while they have {$words}. Loans, savings, time deposits, portal requests and GCash Cash Ins stay in the branch where they were opened, so close or settle them first."]);

        $this->assertSame($this->branch->id, (int) $member->fresh()->branch_id);
        $this->assertSame($before, AuditLog::query()->count());
    }

    public function test_the_refusal_names_everything_open_and_never_asks_to_move_a_loan(): void
    {
        $member = $this->member();
        foreach (['loan:ongoing', 'loan:draft', 'savings:active', 'savings:dormant', 'deposit:active', 'portal:submitted', 'gcash:pending'] as $record) {
            $this->give($member, $record);
        }

        $message = $this->postJson("/api/borrowers/{$member->id}/transfer", ['branch_id' => $this->second->id, 'reason' => self::REASON])
            ->assertUnprocessable()
            ->json('errors.branch_id.0');

        $this->assertStringStartsWith(
            "This member can't be transferred while they have 1 loan still being paid, 1 loan application not yet released, 2 open savings accounts, 1 open time deposit, 1 open portal request and 1 GCash Cash In not yet paid.",
            $message,
        );
        $this->assertStringNotContainsString('move this member', $message);
    }

    public function test_finished_records_do_not_block_a_transfer(): void
    {
        $member = $this->member();
        foreach (['loan:completed', 'loan:rejected', 'loan:void', 'loan:restructured', 'savings:closed', 'deposit:withdrawn', 'deposit:rolled_over', 'deposit:preterminated',
            'portal:completed', 'portal:rejected', 'portal:cancelled', 'portal:expired', 'gcash:paid', 'gcash:completed'] as $record) {
            $this->give($member, $record);
        }
        // Another member's open records are theirs.
        $other = $this->member();
        foreach (['loan:ongoing', 'savings:active', 'deposit:active', 'portal:submitted', 'gcash:pending'] as $record) {
            $this->give($other, $record);
        }

        $this->postJson("/api/borrowers/{$member->id}/transfer", ['branch_id' => $this->second->id, 'reason' => self::REASON])->assertOk();

        $this->assertSame($this->second->id, (int) $member->fresh()->branch_id);
    }

    // A plain edit -------------------------------------------------------------

    public function test_a_plain_edit_that_changes_the_branch_points_to_the_transfer(): void
    {
        $member = $this->member();

        $this->putJson("/api/borrowers/{$member->id}", ['branch_id' => $this->second->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['branch_id' => "A member's branch is changed with Transfer to another branch, not by editing the member. An edit can only fill in a missing branch."]);

        $this->assertSame($this->branch->id, (int) $member->fresh()->branch_id);
    }

    public function test_a_plain_edit_keeping_the_branch_is_accepted_even_with_open_records(): void
    {
        $member = $this->member();
        $this->give($member, 'loan:ongoing');
        $this->give($member, 'savings:active');

        $this->putJson("/api/borrowers/{$member->id}", ['branch_id' => $this->branch->id, 'employer_or_business' => 'Sari-sari store'])->assertOk();

        $this->assertSame('Sari-sari store', $member->fresh()->employer_or_business);
    }

    public function test_a_plain_edit_fills_in_a_missing_branch(): void
    {
        $member = AuditLogService::withoutModelAuditing(fn () => Borrower::factory()->create(['branch_id' => null, 'status' => 'active']));

        $this->putJson("/api/borrowers/{$member->id}", ['branch_id' => $this->second->id])->assertOk();

        $this->assertSame($this->second->id, (int) $member->fresh()->branch_id);
    }

    // Fixtures -----------------------------------------------------------------

    private function member(): Borrower
    {
        return AuditLogService::withoutModelAuditing(fn () => Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']));
    }

    /**
     * @param  list<Branch>  $branches
     * @param  list<string>  $permissions
     */
    private function staffIn(array $branches, array $permissions): User
    {
        $user = User::factory()->inBranches(...$branches)->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    /** Give the member one record, as `kind:status`. */
    private function give(Borrower $member, string $record): void
    {
        [$kind, $status] = explode(':', $record);

        AuditLogService::withoutModelAuditing(function () use ($member, $kind, $status): void {
            match ($kind) {
                'loan' => Loan::factory()->create([
                    'borrower_id' => $member->id,
                    'branch_id' => $member->branch_id,
                    'loan_product_id' => LoanProduct::factory()->create()->id,
                    'status' => $status,
                ]),
                'savings' => SavingsAccount::factory()->create(['borrower_id' => $member->id, 'status' => $status]),
                'deposit' => TimeDeposit::factory()->create(['borrower_id' => $member->id, 'status' => $status]),
                'portal' => PortalRequest::factory()->create([
                    'portal_user_id' => PortalUser::query()->firstOrCreate(['borrower_id' => $member->id], PortalUser::factory()->raw(['borrower_id' => $member->id]))->id,
                    'status' => $status,
                ]),
                'gcash' => GCashTransaction::factory()->create(['borrower_id' => $member->id, 'type' => 'cash_in', 'status' => $status]),
            };
        });
    }
}
