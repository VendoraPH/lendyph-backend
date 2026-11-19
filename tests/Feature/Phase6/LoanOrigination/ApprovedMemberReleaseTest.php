<?php

use App\Models\ApprovalWorkflowSetting;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\OriginationSetting;
use App\Models\User;
use App\Services\LoanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/*
 * LO-B4, the approved-member setting (Phase 6 owner question 11, standard
 * default): a loan isn't released while its member's registration isn't
 * approved, unless the setting is turned off. On for new releases from the
 * start. Checked by the release request and again under the loan lock.
 */
uses(TestCase::class);

beforeEach(function () {
    $this->admin = User::query()->where('username', 'super_admin')->firstOrFail();
    $this->actingAs($this->admin);
    ApprovalWorkflowSetting::query()->updateOrCreate(['type' => ApprovalWorkflowSetting::TYPE_NORMAL], ['steps' => [
        ['id' => 'submit', 'name' => 'Submit', 'role' => 'super_admin', 'kind' => 'submit'],
        ['id' => 'release', 'name' => 'Release', 'role' => 'super_admin', 'kind' => 'release'],
    ]]);
});

function lo4ApprovedLoanFor(string $memberStatus): Loan
{
    $product = LoanProduct::factory()->create(['interest_rate' => 2, 'term' => 6, 'frequency' => 'monthly', 'min_amount' => 1000, 'max_amount' => 100000]);
    $member = Borrower::factory()->create(['status' => 'active', 'branch_id' => Branch::query()->value('id'), 'first_name' => 'Ana', 'last_name' => 'Reyes', 'middle_name' => null, 'suffix' => null]);
    $loans = app(LoanService::class);
    $loan = $loans->createLoan(['borrower_id' => $member->id, 'loan_product_id' => $product->id, 'principal_amount' => 30000, 'start_date' => now()->toDateString()], User::query()->where('username', 'super_admin')->firstOrFail());
    $loans->submitForReview($loan);
    DB::table('borrowers')->where('id', $member->id)->update(['status' => $memberStatus]);

    return $loan->fresh();
}

it('is on by default', function () {
    expect(OriginationSetting::current()->require_approved_member)->toBeTrue();
});

it('refuses the release of a loan whose member is still a pending registration, on the request and under the lock', function () {
    $loan = lo4ApprovedLoanFor('pending');
    $code = $loan->borrower->borrower_code;
    $message = "Ana Reyes ({$code}) isn't an approved member yet: approve their membership registration before releasing this loan.";

    $this->patchJson("/api/loans/{$loan->id}/release")->assertUnprocessable()->assertJsonPath('errors.borrower_id.0', $message);
    expect(fn () => app(LoanService::class)->release($loan, $this->admin))->toThrow(ValidationException::class, $message)
        ->and($loan->fresh()->status)->toBe('approved');
});

it('releases once the member is approved, or when the setting is off', function () {
    $active = lo4ApprovedLoanFor('active');
    app(LoanService::class)->release($active, $this->admin);

    DB::table('origination_settings')->update(['require_approved_member' => false]);
    $pending = lo4ApprovedLoanFor('pending');
    app(LoanService::class)->release($pending, $this->admin);

    expect($active->fresh()->status)->toBe('released')
        ->and($pending->fresh()->status)->toBe('released');
});

it('is read and saved with the online application settings, by company-wide settings holders, and audited', function () {
    $this->getJson('/api/loan-origination/settings')->assertOk()->assertJsonPath('data.general.require_approved_member', true);

    $this->putJson('/api/loan-origination/settings', ['general' => ['require_approved_member' => false]])->assertOk();

    expect(OriginationSetting::current()->require_approved_member)->toBeFalse()
        ->and(AuditLog::query()->where('auditable_type', OriginationSetting::class)->latest('id')->first()->new_values)->toHaveKey('require_approved_member');

    $this->putJson('/api/loan-origination/settings', ['general' => ['require_approved_member' => 'maybe']])->assertUnprocessable()
        ->assertJsonValidationErrors(['general.require_approved_member']);
});
