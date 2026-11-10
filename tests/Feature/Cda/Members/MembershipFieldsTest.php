<?php

namespace Tests\Feature\Cda\Members;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Role;
use App\Models\SavingsAccount;
use App\Models\ShareCapitalLedger;
use App\Models\TimeDeposit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The member fields (CDA design §1): approving a registration and staff
 * creating a member set the membership date (today, Manila) and number (the
 * member code); a membership ends as withdrawn (refused, in words, while a
 * loan, savings or share capital is still open) or deceased (always), and
 * reactivating a former member needs a reason. Each is audited.
 */
class MembershipFieldsTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-11 00:30:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_approving_a_registration_sets_the_membership_date_and_number(): void
    {
        $applicant = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'pending']);
        $this->assertNull($applicant->fresh()->membership_date);
        $applicant->documents()->create(['type' => 'valid_id', 'label' => 'umid', 'file_path' => 'documents/valid_id/t.jpg', 'original_filename' => 't.jpg']);

        $this->patchJson("/api/borrowers/{$applicant->id}/approve-registration")
            ->assertOk()
            ->assertJsonPath('data.membership_date', '2026-11-11')
            ->assertJsonPath('data.membership_number', $applicant->borrower_code)
            ->assertJsonPath('data.member_type', 'regular');

        $fresh = $applicant->fresh();
        $this->assertSame('2026-11-11', $fresh->membership_date->toDateString());
        $this->assertSame($applicant->borrower_code, $fresh->membership_number);
    }

    public function test_staff_creating_a_member_sets_the_fields_and_a_public_registration_does_not(): void
    {
        $created = $this->postJson('/api/borrowers', [
            'first_name' => 'Ana', 'last_name' => 'Reyes', 'branch_id' => $this->branch->id,
            'street_address' => '1 Rizal St', 'city' => 'Butuan', 'province' => 'Agusan del Norte',
            'birthdate' => '1990-05-12', 'civil_status' => 'single', 'gender' => 'female', 'contact_number' => '09170000333',
        ])->assertCreated();

        $member = Borrower::query()->findOrFail($created->json('data.id'));
        $this->assertSame('2026-11-11', $member->membership_date->toDateString());
        $this->assertSame($member->borrower_code, $member->membership_number);

        $pending = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'pending']);
        $this->assertNull($pending->fresh()->membership_date);
        $this->assertNull($pending->fresh()->membership_number);
    }

    public function test_the_member_type_and_membership_date_can_be_edited(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->putJson("/api/borrowers/{$member->id}", ['member_type' => 'associate', 'membership_date' => '2019-03-01'])
            ->assertOk()
            ->assertJsonPath('data.member_type', 'associate')
            ->assertJsonPath('data.membership_date', '2019-03-01');

        $this->putJson("/api/borrowers/{$member->id}", ['member_type' => 'honorary'])->assertUnprocessable()->assertJsonValidationErrors('member_type');
        $this->putJson("/api/borrowers/{$member->id}", ['membership_date' => '2026-12-01'])->assertUnprocessable()->assertJsonValidationErrors('membership_date');
    }

    public function test_withdrawing_is_refused_in_words_while_a_loan_savings_or_share_capital_is_open(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        Loan::factory()->create([
            'borrower_id' => $member->id, 'branch_id' => $this->branch->id, 'status' => 'released',
            'loan_product_id' => LoanProduct::factory()->create()->id, 'loan_account_number' => 'LN-777001',
        ]);
        SavingsAccount::factory()->create(['borrower_id' => $member->id]);
        TimeDeposit::factory()->create(['borrower_id' => $member->id, 'status' => 'active']);
        ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'credit' => 1500, 'debit' => 0]);

        $response = $this->patchJson("/api/borrowers/{$member->id}/end-membership", [
            'status' => 'withdrawn', 'ended_on' => '2026-11-10', 'reason' => 'Moved abroad',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $message = $response->json('errors.status.0');
        $this->assertStringContainsString('1 loan still being paid', $message);
        $this->assertStringContainsString('1 open savings account', $message);
        $this->assertStringContainsString('1 open time deposit', $message);
        $this->assertStringContainsString('share capital of ₱1,500.00', $message);
        $this->assertSame('active', $member->fresh()->status);
    }

    public function test_withdrawing_a_member_with_nothing_open_ends_the_membership_with_one_audit_row(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        SavingsAccount::factory()->closed()->create(['borrower_id' => $member->id]);
        ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'credit' => 1500, 'debit' => 0]);
        ShareCapitalLedger::factory()->create(['borrower_id' => $member->id, 'credit' => 0, 'debit' => 1500]);
        $before = AuditLog::query()->max('id');

        $this->patchJson("/api/borrowers/{$member->id}/end-membership", [
            'status' => 'withdrawn', 'ended_on' => '2026-11-10', 'reason' => 'Moved abroad',
        ])->assertOk()
            ->assertJsonPath('data.status', 'withdrawn')
            ->assertJsonPath('data.membership_ended_on', '2026-11-10')
            ->assertJsonPath('data.membership_end_reason', 'Moved abroad');

        $rows = AuditLog::query()->where('id', '>', $before)->where('auditable_type', Borrower::class)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['status' => 'withdrawn', 'membership_ended_on' => '2026-11-10', 'membership_end_reason' => 'Moved abroad'], $rows->first()->new_values);
        $this->assertStringContainsString('Membership ended', (string) $rows->first()->description);
    }

    public function test_a_deceased_member_is_always_allowed_and_bad_input_is_refused(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        Loan::factory()->create([
            'borrower_id' => $member->id, 'branch_id' => $this->branch->id, 'status' => 'ongoing',
            'loan_product_id' => LoanProduct::factory()->create()->id,
        ]);

        $this->patchJson("/api/borrowers/{$member->id}/end-membership", ['status' => 'expelled', 'ended_on' => '2026-11-10', 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->patchJson("/api/borrowers/{$member->id}/end-membership", ['status' => 'deceased', 'ended_on' => '2026-11-12', 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('ended_on');
        $this->patchJson("/api/borrowers/{$member->id}/end-membership", ['status' => 'deceased', 'ended_on' => '2026-11-10'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->patchJson("/api/borrowers/{$member->id}/end-membership", ['status' => 'deceased', 'ended_on' => '2026-11-10', 'reason' => 'Death certificate on file'])
            ->assertOk()->assertJsonPath('data.status', 'deceased');

        $this->patchJson("/api/borrowers/{$member->id}/end-membership", ['status' => 'withdrawn', 'ended_on' => '2026-11-10', 'reason' => 'again'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_an_applicant_has_no_membership_to_end(): void
    {
        $applicant = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'pending']);

        $this->patchJson("/api/borrowers/{$applicant->id}/end-membership", ['status' => 'deceased', 'ended_on' => '2026-11-10', 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_reactivating_a_former_member_needs_a_reason_and_is_audited(): void
    {
        $member = Borrower::factory()->create([
            'branch_id' => $this->branch->id, 'status' => 'withdrawn',
            'membership_ended_on' => '2026-10-01', 'membership_end_reason' => 'Moved abroad',
        ]);

        $this->patchJson("/api/borrowers/{$member->id}/reactivate")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame('withdrawn', $member->fresh()->status);

        $before = AuditLog::query()->max('id');
        $this->patchJson("/api/borrowers/{$member->id}/reactivate", ['reason' => 'Rejoined after returning'])->assertOk();

        $fresh = $member->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertNull($fresh->membership_ended_on);
        $this->assertNull($fresh->membership_end_reason);
        $row = AuditLog::query()->where('id', '>', $before)->where('auditable_type', Borrower::class)->sole();
        $this->assertStringContainsString('Rejoined after returning', (string) $row->description);
        $this->assertSame('withdrawn', $row->old_values['status']);
    }

    public function test_an_inactive_member_still_reactivates_without_a_reason_and_a_former_one_is_not_deactivated(): void
    {
        $inactive = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'inactive']);
        $this->patchJson("/api/borrowers/{$inactive->id}/reactivate")->assertOk();
        $this->assertSame('active', $inactive->fresh()->status);

        $deceased = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'deceased']);
        $this->patchJson("/api/borrowers/{$deceased->id}/deactivate")->assertUnprocessable()->assertJsonValidationErrors('status');

        $bulk = $this->patchJson('/api/borrowers/bulk-deactivate', ['ids' => [$deceased->id]])->assertOk();
        $this->assertSame([], $bulk->json('deactivated'));
        $this->assertSame('deceased', $deceased->fresh()->status);
    }

    public function test_ending_a_membership_needs_borrowers_update(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $role = Role::create(['name' => 'member_viewer_probe', 'guard_name' => 'web']);
        $role->givePermissionTo(['borrowers:view']);
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user)
            ->patchJson("/api/borrowers/{$member->id}/end-membership", ['status' => 'deceased', 'ended_on' => '2026-11-10', 'reason' => 'x'])
            ->assertForbidden();
    }
}
