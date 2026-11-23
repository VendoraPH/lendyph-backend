<?php

use App\Models\ApprovalWorkflowSetting;
use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\CreditApplicationProfile;
use App\Models\CreditScoringSetting;
use App\Models\Document;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\OriginationApplication;
use App\Models\OriginationAssessment;
use App\Models\OriginationDocument;
use App\Models\OriginationSetting;
use App\Models\OriginationStatusHistory;
use App\Models\ShareCapitalPledge;
use App\Models\User;
use App\Services\Branches\MemberLock;
use App\Services\CreditScoring\Capacity;
use App\Services\CreditScoring\Centavos;
use App\Services\CreditScoring\Exposure;
use App\Services\CreditScoring\MemberCreditData;
use App\Services\LoanListTotals;
use App\Services\LoanOrigination\Assessment\AssessmentService;
use App\Services\LoanOrigination\Assessment\CapacityAssessor;
use App\Services\LoanOrigination\HandOff\ApplicationHandOff;
use App\Services\LoanService;
use App\Services\ValidIdService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Phase6\LoanOrigination\OriginationFixtures;
use Tests\TestCase;

/*
 * LO-B4: "Submit for approval" is the hand-off (origination decisions 11–13,
 * Phase 6 decision 9). It links or creates the member, then creates the draft
 * loan through the loan code (BranchWriteGuard, createLoanRecord) and submits
 * it into the approval chain, in one loan transaction. It needs
 * `loan_origination:convert` and `loans:create`, plus `borrowers:create` for a
 * new member.
 */
uses(TestCase::class, OriginationFixtures::class);

beforeEach(function () {
    $this->pinOriginationToday();
    Storage::fake('private');
});

const LO4_HANDOFF = ['loan_origination:view', 'loan_origination:assess', 'loan_origination:convert', 'loans:create', 'borrowers:create', 'borrowers:view'];

function lo4HandOffUrl(int $id): string
{
    return "/api/loan-origination/applications/{$id}/submit-for-approval";
}

/** An application under assessment with verified documents and a current assessment. */
function lo4Ready(TestCase $test, array $draft = [], string $verifiedIncome = '40000', ?array $attributes = []): OriginationApplication
{
    $application = $test->originationApplication('under_assessment', null, $attributes ?? [], $draft);
    $test->originationDocuments($application);
    $test->actingAs($test->originationStaff(...LO4_HANDOFF))
        ->postJson("/api/loan-origination/applications/{$application->id}/assessment/rerun", ['verified_monthly_income' => $verifiedIncome, 'verified_debt_payments' => '1500'])
        ->assertCreated();

    return $application->fresh();
}

it('creates a pending member through the member registration code, then a submitted loan, and follows it', function () {
    $application = lo4Ready($this);
    $staff = $this->originationStaff(...LO4_HANDOFF);

    $response = $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->version]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'pending_approval')
        ->assertJsonPath('data.status_label', 'For Approval');

    $application->refresh();
    $member = Borrower::query()->findOrFail($application->borrower_id);
    $loan = Loan::query()->findOrFail($application->loan_id);

    expect($member->status)->toBe('pending')
        ->and($member->membership_date)->toBeNull()
        ->and($member->borrower_code)->toStartWith('BRW-')
        ->and($member->branch_id)->toBe($application->branch_id)
        ->and($member->first_name)->toBe('Maria')
        ->and($member->gender)->toBe('female')
        ->and($member->contact_number)->toBe('09171234567')
        ->and(ShareCapitalPledge::query()->where('borrower_id', $member->id)->exists())->toBeTrue()
        ->and($loan->status)->toBe('for_review')
        ->and($loan->borrower_id)->toBe($member->id)
        ->and((float) $loan->principal_amount)->toBe(30000.0)
        ->and($loan->term)->toBe(6)
        ->and($loan->start_date->toDateString())->toBe('2026-11-19')
        ->and($loan->created_by)->toBe($staff->id)
        ->and($loan->policy_exception)->toBeFalse()
        ->and(LoanApprovalStep::query()->where('loan_id', $loan->id)->exists())->toBeTrue()
        ->and(OriginationStatusHistory::query()->where('application_id', $application->id)->latest('id')->first())
        ->to_status->toBe('pending_approval')
        ->actor_type->toBe('handoff');
    expect($response->json('data.loan.id'))->toBe($loan->id)
        ->and($response->json('data.loan.application_number'))->toBe($loan->application_number);
});

it('creates the same loan and chain as POST /loans and its submit would', function () {
    $application = lo4Ready($this);
    $staff = $this->originationStaff(...LO4_HANDOFF);
    $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])->assertOk();
    $handedOff = Loan::query()->findOrFail($application->fresh()->loan_id);

    $loans = app(LoanService::class);
    $direct = $loans->createLoan([
        'borrower_id' => $handedOff->borrower_id,
        'loan_product_id' => $handedOff->loan_product_id,
        'principal_amount' => 30000,
        'term' => 6,
        'frequency' => 'monthly',
        'purpose' => 'Store stock',
        'start_date' => '2026-11-19',
        'scb_amount' => 0,
        'policy_exception' => false,
    ], $staff);
    $loans->submitForReview($direct, $staff);

    $ignore = ['id', 'application_number', 'created_at', 'updated_at'];
    $columns = fn (Loan $loan) => collect($loan->fresh()->getAttributes())->except($ignore)->all();
    $steps = fn (Loan $loan) => LoanApprovalStep::query()->where('loan_id', $loan->id)->orderBy('step_order')
        ->get(['step_order', 'name', 'role', 'kind', 'status'])->toArray();

    expect($columns($handedOff))->toBe($columns($direct))
        ->and($steps($handedOff))->toBe($steps($direct));
});

it('takes the assessed instalment Credit Scoring\'s capacity takes from the created loan', function () {
    $application = lo4Ready($this, [], '40000');
    $this->actingAs($this->originationStaff(...LO4_HANDOFF))->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])->assertOk();
    $application->refresh();
    $loan = Loan::query()->findOrFail($application->loan_id);
    $assessment = OriginationAssessment::query()->where('application_id', $application->id)->latest('version')->firstOrFail();

    $proposed = Capacity::proposedPayment($loan, app(LoanService::class));
    $data = MemberCreditData::load($loan->borrower_id, $loan, now());
    $scored = Capacity::from($data, Exposure::from($data, app(LoanListTotals::class)), CreditScoringSetting::current(), app(LoanService::class));

    expect($assessment->figures['proposed_monthly_installment'])->toBe(number_format($proposed['centavos'] / 100, 2, '.', ''))
        ->and($scored->incomeCentavos)->toBe(Centavos::of($assessment->figures['eligible_monthly_income']))
        ->and($scored->existingDebtCentavos)->toBe(Centavos::of($assessment->figures['monthly_debt_payments']))
        ->and($this->ratioAsAssessed($scored))->toBe($assessment->figures['dti_ratio'])
        ->and($scored->toArray()['disposable_income'])->toBe($assessment->figures['disposable_income']);
});

it('records the application details on the loan for Credit Scoring', function () {
    $application = lo4Ready($this, ['employment' => ['other_regular_income' => '2000']], '42000');
    $staff = $this->originationStaff(...LO4_HANDOFF);
    $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])->assertOk();

    $profile = CreditApplicationProfile::query()->where('loan_id', $application->fresh()->loan_id)->sole();
    $member = Borrower::query()->findOrFail($application->fresh()->borrower_id);

    expect((string) $member->monthly_income)->toBe('42000.00')
        ->and($profile->other_income)->toBeNull()
        ->and($profile->household_expenses)->toBe('14000.00')
        ->and($profile->outside_debt_payments)->toBe('1500.00')
        ->and($profile->employment_status)->toBe('employed')
        ->and($profile->income_verification)->toBe('verified')
        ->and($profile->created_by)->toBe($staff->id);
});

it('sends an above-capacity result to the Policy Exception chain with its explanation', function () {
    $application = lo4Ready($this, ['employment' => ['gross_monthly_income' => '9000']], '9000');

    $this->actingAs($this->originationStaff(...LO4_HANDOFF))->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])->assertOk();
    $loan = Loan::query()->findOrFail($application->fresh()->loan_id);

    expect($loan->policy_exception)->toBeTrue()
        ->and($loan->policy_exception_details)->toStartWith('Online application '.$application->reference_number.': Above capacity')
        ->and($loan->policy_exception_details)->toContain('debt-to-income ratio');
});

it('sends an insufficient-information result to the Policy Exception chain with its explanation', function () {
    $application = $this->originationApplication('under_assessment', null, [], ['employment' => ['gross_monthly_income' => '']]);
    $this->originationDocuments($application);
    $staff = $this->originationStaff(...LO4_HANDOFF);
    $this->actingAs($staff)->postJson("/api/loan-origination/applications/{$application->id}/assessment/rerun", ['verified_debt_payments' => '1500'])
        ->assertCreated()->assertJsonPath('data.result', 'insufficient_information');

    $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->fresh()->version])
        ->assertOk()->assertJsonPath('data.loan.policy_exception', true);
    $loan = Loan::query()->findOrFail($application->fresh()->loan_id);

    expect($loan->policy_exception_details)->toBe('Online application '.$application->reference_number.': Insufficient information: no monthly income was given.')
        ->and(LoanApprovalStep::query()->where('loan_id', $loan->id)->exists())->toBeTrue();
});

it('copies the verified documents to the member\'s documents, the government ID as their valid ID with its type and number', function () {
    $application = lo4Ready($this);
    $this->actingAs($this->originationStaff(...LO4_HANDOFF))->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])->assertOk();
    $member = Borrower::query()->findOrFail($application->fresh()->borrower_id);

    $documents = Document::query()->where('documentable_type', Borrower::class)->where('documentable_id', $member->id)->orderBy('type')->get()->keyBy('type');

    expect($documents->keys()->all())->toBe(['proof_of_address', 'proof_of_income', 'valid_id'])
        ->and($documents['proof_of_income']->label)->toBe('Proof of income (online application '.$application->reference_number.')')
        ->and($documents['valid_id']->only(['label', 'custom_type_name', 'id_number', 'side']))->toBe(['label' => 'drivers_license', 'custom_type_name' => null, 'id_number' => 'N01-23-456789', 'side' => null])
        ->and(app(ValidIdService::class)->grouped($member)->sole())->toMatchArray(['type' => 'drivers_license', 'id_number' => 'N01-23-456789'])
        ->and($documents['valid_id']->file_path)->toStartWith(ValidIdService::directoryFor($member).'/');
    foreach ($documents as $document) {
        Storage::disk('private')->assertExists($document->file_path);
        expect($document->file_path)->toStartWith("documents/{$document->type}/borrower/{$member->id}/");
    }
});

it('refuses, in words, a verified government ID whose type and number weren\'t recorded', function () {
    $application = lo4Ready($this);
    OriginationDocument::query()->where('application_id', $application->id)->where('type', 'government_id')->sole()->update(['id_details' => null]);

    $this->actingAs($this->originationStaff(...LO4_HANDOFF))->postJson(lo4HandOffUrl($application->id), ['version' => $application->fresh()->version])
        ->assertUnprocessable()
        ->assertJsonPath('errors.documents.0', 'Valid government ID was verified without its ID type and number. Request a replacement and verify the new file with them.');

    expect(Loan::query()->count())->toBe(0)
        ->and($application->fresh()->borrower_id)->toBeNull();
});

it('hands off a new applicant whose verified ID lets their registration be approved, and the loan released', function () {
    ApprovalWorkflowSetting::query()->updateOrCreate(['type' => ApprovalWorkflowSetting::TYPE_NORMAL], ['steps' => [
        ['id' => 'submit', 'name' => 'Submit', 'role' => 'super_admin', 'kind' => 'submit'],
        ['id' => 'release', 'name' => 'Release', 'role' => 'super_admin', 'kind' => 'release'],
    ]]);
    $application = lo4Ready($this);
    $admin = User::query()->where('username', 'super_admin')->firstOrFail();

    $this->actingAs($admin)->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])->assertOk();
    $application->refresh();
    $member = Borrower::query()->findOrFail($application->borrower_id);
    expect($member->status)->toBe('pending')
        ->and(Loan::query()->findOrFail($application->loan_id)->status)->toBe('approved');

    $this->actingAs($admin)->patchJson("/api/borrowers/{$member->id}/approve-registration")->assertOk()->assertJsonPath('data.status', 'active');
    $this->actingAs($admin)->patchJson("/api/loans/{$application->loan_id}/release")->assertOk();

    expect(Loan::query()->findOrFail($application->loan_id)->status)->toBe('released')
        ->and($application->fresh()->status)->toBe('converted');
});

it('links an already linked member and never overwrites their record', function () {
    $member = Borrower::factory()->create(['status' => 'active', 'branch_id' => Branch::query()->value('id'), 'first_name' => 'Maria', 'monthly_income' => 15000]);
    $application = lo4Ready($this, [], '40000', ['borrower_id' => $member->id]);
    $before = $member->fresh()->getAttributes();

    $this->actingAs($this->originationStaff(...array_diff(LO4_HANDOFF, ['borrowers:create'])))
        ->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])->assertOk();

    expect(Loan::query()->findOrFail($application->fresh()->loan_id)->borrower_id)->toBe($member->id)
        ->and(collect($member->fresh()->getAttributes())->except('updated_at')->all())->toBe(collect($before)->except('updated_at')->all());
});

it('needs the convert and loan create permissions, and the member create one for a new member, in words', function (array $without, string $message) {
    $application = lo4Ready($this);

    $this->actingAs($this->originationStaff(...array_diff(LO4_HANDOFF, $without)))
        ->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])
        ->assertForbidden()->assertJsonPath('message', $message);

    expect($application->fresh()->borrower_id)->toBeNull()
        ->and(Loan::query()->count())->toBe(0);
})->with([
    'convert' => [['loan_origination:convert'], 'Moving an application to For Approval needs the permission to hand applications off and the permission to create loans.'],
    'loans:create' => [['loans:create'], 'Moving an application to For Approval needs the permission to hand applications off and the permission to create loans.'],
    'borrowers:create' => [['borrowers:create'], 'Handing this application off creates its member, which needs the permission to create members.'],
]);

it('answers a repeated hand-off with 409 naming the loan', function () {
    $application = lo4Ready($this);
    $staff = $this->originationStaff(...LO4_HANDOFF);
    $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])->assertOk();
    $loan = Loan::query()->findOrFail($application->fresh()->loan_id);

    $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->fresh()->version])
        ->assertStatus(409)
        ->assertJsonPath('message', "This application was already handed off as loan {$loan->application_number}.")
        ->assertJsonPath('loan_id', $loan->id);
    expect(Loan::query()->count())->toBe(1);
});

it('answers 409 when the application changed since the client read it', function () {
    $application = lo4Ready($this);

    $this->actingAs($this->originationStaff(...LO4_HANDOFF))
        ->postJson(lo4HandOffUrl($application->id), ['version' => $application->version + 1])
        ->assertStatus(409);
});

it('refuses, in words, an application not under assessment, without a current assessment, or with unverified documents', function () {
    $staff = $this->originationStaff(...LO4_HANDOFF);

    $submitted = $this->originationApplication('submitted');
    $this->actingAs($staff)->postJson(lo4HandOffUrl($submitted->id), ['version' => $submitted->version])
        ->assertUnprocessable()->assertJsonPath('errors.status.0', 'Only an application under assessment can be submitted for approval.');

    $unassessed = $this->originationApplication();
    $this->originationDocuments($unassessed);
    $this->actingAs($staff)->postJson(lo4HandOffUrl($unassessed->id), ['version' => $unassessed->version])
        ->assertUnprocessable()->assertJsonPath('errors.assessment.0', 'Run the assessment before submitting the application for approval.');

    $unverified = $this->originationApplication();
    $this->originationDocuments($unverified, 'pending_verification');
    $this->actingAs($staff)->postJson("/api/loan-origination/applications/{$unverified->id}/assessment/rerun")->assertCreated();
    $this->actingAs($staff)->postJson(lo4HandOffUrl($unverified->id), ['version' => $unverified->fresh()->version])
        ->assertUnprocessable()->assertJsonPath('errors.documents.0', 'Verify these documents first: Valid government ID, Proof of income.');

    expect(Loan::query()->count())->toBe(0);
});

it('refuses a hand-off whose assessment no longer matches the application\'s request or member', function () {
    $application = lo4Ready($this);
    DB::table('origination_applications')->where('id', $application->id)->update(['requested_amount' => 35000]);
    $application->refresh();

    $this->actingAs($this->originationStaff(...LO4_HANDOFF))->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])
        ->assertUnprocessable()
        ->assertJsonPath('errors.assessment.0', 'The application changed after it was assessed. Run the assessment again.');
});

it('books the loan through the branch guard, so a suspended branch takes no new loan', function () {
    $application = lo4Ready($this);
    $staff = $this->originationStaff(...LO4_HANDOFF);
    $member = Borrower::factory()->create(['status' => 'active', 'branch_id' => Branch::factory()->create()->id]);
    DB::table('origination_applications')->where('id', $application->id)->update(['borrower_id' => $member->id]);
    $this->actingAs($this->originationStaff(...LO4_HANDOFF, ...['branches:view_all']))->postJson("/api/loan-origination/applications/{$application->id}/assessment/rerun")->assertCreated();
    DB::table('branches')->where('id', $member->branch_id)->update(['status' => Branch::STATUS_SUSPENDED]);
    $staff->givePermissionTo('branches:view_all');

    $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->fresh()->version])
        ->assertUnprocessable()
        ->assertJsonPath('errors.borrower_id.0', fn (string $m) => str_contains($m, 'no new loans can be made there'));
    expect(Loan::query()->count())->toBe(0);
});

it('keeps a new member linked when the loan step fails, and a retry resumes without a second member', function () {
    $application = lo4Ready($this);
    $staff = $this->originationStaff(...LO4_HANDOFF);

    $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->version, 'account_officer_id' => 999999])
        ->assertUnprocessable()->assertJsonValidationErrors(['account_officer_id']);
    $application->refresh();
    $memberId = $application->borrower_id;
    expect($memberId)->not->toBeNull()->and(Loan::query()->count())->toBe(0);

    $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])->assertOk();

    expect($application->fresh()->borrower_id)->toBe($memberId)
        ->and(Borrower::query()->where('first_name', 'Maria')->where('last_name', $application->draft['personal']['last_name'])->count())->toBe(1)
        ->and(Loan::query()->sole()->borrower_id)->toBe($memberId);
});

it('rolls the copied files back with the loan when the loan step fails', function () {
    $application = lo4Ready($this);
    $staff = $this->originationStaff(...LO4_HANDOFF);
    Storage::disk('private')->delete(OriginationApplication::filesFolder($application->id).'/proof_of_address-v1.pdf');

    $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])
        ->assertUnprocessable()
        ->assertJsonPath('errors.documents.0', 'The file of Proof of address can\'t be read. Ask the applicant to upload it again.');

    expect(Document::query()->where('documentable_type', Borrower::class)->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles('documents'))->toBe([])
        ->and(Loan::query()->count())->toBe(0)
        ->and($application->fresh()->status)->toBe('under_assessment');
});

it('writes an audit row on the application with ids only', function () {
    $application = lo4Ready($this);
    $this->actingAs($this->originationStaff(...LO4_HANDOFF))->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])->assertOk();

    $row = AuditLog::query()->where('auditable_type', OriginationApplication::class)->where('auditable_id', $application->id)->where('action', 'handed_off')->sole();

    expect($row->new_values)->toBe(['loan_id' => $application->fresh()->loan_id, 'borrower_id' => $application->fresh()->borrower_id, 'policy_exception' => false])
        ->and((string) $row->description)->not->toContain('Maria');
});

it('re-checks the capacity at the loan\'s start date, so a weekly loan whose busiest month changes is assessed again first', function () {
    $product = $this->originationProduct(['frequency' => 'weekly', 'term' => 3]);
    $application = $this->originationApplication('under_assessment', $product, [], ['loan' => ['frequency' => 'weekly', 'term' => '3']]);
    $this->originationDocuments($application);
    $staff = $this->originationStaff(...LO4_HANDOFF);
    $rerun = "/api/loan-origination/applications/{$application->id}/assessment/rerun";
    $this->actingAs($staff)->postJson($rerun, ['verified_monthly_income' => '40000', 'verified_debt_payments' => '1500'])->assertCreated();

    // Assessed from Thursday Nov 19, when December holds five instalments;
    // from Monday Nov 23 no month holds more than four.
    $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->fresh()->version, 'start_date' => '2026-11-23'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.assessment.0', 'The result or the instalment is different for a loan starting on Nov 23, 2026 at the product\'s current rate. Run the assessment again with that start date.');
    expect(Loan::query()->count())->toBe(0)
        ->and($application->fresh()->borrower_id)->toBeNull();

    $this->actingAs($staff)->postJson($rerun, ['start_date' => '2026-11-23'])->assertCreated()->assertJsonPath('data.start_date', '2026-11-23');
    $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->fresh()->version, 'start_date' => '2026-11-23'])->assertOk();

    $loan = Loan::query()->sole();
    $proposed = Capacity::proposedPayment($loan, app(LoanService::class));
    expect($loan->start_date->toDateString())->toBe('2026-11-23')
        ->and(OriginationAssessment::currentFor($application->fresh())->figures['proposed_monthly_installment'])->toBe(number_format($proposed['centavos'] / 100, 2, '.', ''));
});

it('re-checks the capacity at the product\'s current rate', function () {
    $application = lo4Ready($this);
    $application->loanProduct->update(['interest_rate' => 3.5]);

    $this->actingAs($this->originationStaff(...LO4_HANDOFF))->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])
        ->assertUnprocessable()
        ->assertJsonPath('errors.assessment.0', 'The result or the instalment is different for a loan starting on Nov 19, 2026 at the product\'s current rate. Run the assessment again with that start date.');
    expect(Loan::query()->count())->toBe(0);
});

it('scores a linked member\'s loan on the application\'s verified income, so Credit Scoring\'s ratio equals the assessment\'s', function () {
    $member = Borrower::factory()->create(['status' => 'active', 'branch_id' => Branch::query()->value('id'), 'monthly_income' => 15000]);
    $application = lo4Ready($this, [], '40000', ['borrower_id' => $member->id]);
    $this->actingAs($this->originationStaff(...LO4_HANDOFF))->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])->assertOk();
    $loan = Loan::query()->findOrFail($application->fresh()->loan_id);
    $assessment = OriginationAssessment::currentFor($application->fresh());

    $data = MemberCreditData::load($member->id, $loan, now());
    $scored = Capacity::from($data, Exposure::from($data, app(LoanListTotals::class)), CreditScoringSetting::current(), app(LoanService::class));

    expect(CreditApplicationProfile::query()->where('loan_id', $loan->id)->sole()->monthly_income)->toBe('40000.00')
        ->and($scored->toArray()['monthly_income'])->toBe('40000.00')
        ->and($scored->existingDebtCentavos)->toBe(Centavos::of($assessment->figures['monthly_debt_payments']))
        ->and($scored->proposedPaymentCentavos)->toBe(Centavos::of($assessment->figures['proposed_monthly_installment']))
        ->and($this->ratioAsAssessed($scored))->toBe($assessment->figures['dti_ratio'])
        ->and($scored->toArray()['disposable_income'])->toBe($assessment->figures['disposable_income'])
        ->and((string) $member->fresh()->monthly_income)->toBe('15000.00');
});

it('scores a linked member\'s loan on the income its assessment counted when none was verified, leaving it unverified', function () {
    $member = Borrower::factory()->create(['status' => 'active', 'branch_id' => Branch::query()->value('id'), 'monthly_income' => 15000]);
    $application = $this->originationApplication('under_assessment', null, ['borrower_id' => $member->id]);
    $this->originationDocuments($application);
    $staff = $this->originationStaff(...LO4_HANDOFF);
    $this->actingAs($staff)->postJson("/api/loan-origination/applications/{$application->id}/assessment/rerun", ['verified_debt_payments' => '1500'])->assertCreated();
    $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->fresh()->version])->assertOk();
    $loan = Loan::query()->findOrFail($application->fresh()->loan_id);
    $assessment = OriginationAssessment::currentFor($application->fresh());
    $profile = CreditApplicationProfile::query()->where('loan_id', $loan->id)->sole();

    $data = MemberCreditData::load($member->id, $loan, now());
    $scored = Capacity::from($data, Exposure::from($data, app(LoanListTotals::class)), CreditScoringSetting::current(), app(LoanService::class));

    expect($assessment->figures['verified_monthly_income'])->toBeNull()
        ->and($profile->monthly_income)->toBe($assessment->figures['eligible_monthly_income'])
        ->and($profile->monthly_income)->toBe('40000.00')
        ->and($profile->other_income)->toBeNull()
        ->and($profile->income_verification)->toBeNull()
        ->and($scored->toArray()['monthly_income'])->toBe('40000.00')
        ->and($this->ratioAsAssessed($scored))->toBe($assessment->figures['dti_ratio']);
});

it('records no income on the loan\'s credit profile when its assessment counted none, so the score never reads the member record\'s', function () {
    $member = Borrower::factory()->create(['status' => 'active', 'branch_id' => Branch::query()->value('id'), 'monthly_income' => 15000]);
    $application = $this->originationApplication('under_assessment', null, ['borrower_id' => $member->id], ['employment' => ['gross_monthly_income' => '']]);
    $this->originationDocuments($application);
    $staff = $this->originationStaff(...LO4_HANDOFF);
    $this->actingAs($staff)->postJson("/api/loan-origination/applications/{$application->id}/assessment/rerun", ['verified_debt_payments' => '1500'])
        ->assertCreated()->assertJsonPath('data.result', 'insufficient_information');
    $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->fresh()->version])->assertOk();
    $loan = Loan::query()->findOrFail($application->fresh()->loan_id);
    $profile = CreditApplicationProfile::query()->where('loan_id', $loan->id)->sole();

    $data = MemberCreditData::load($member->id, $loan, now());
    $scored = Capacity::from($data, Exposure::from($data, app(LoanListTotals::class)), CreditScoringSetting::current(), app(LoanService::class));

    expect($loan->policy_exception)->toBeTrue()
        ->and($profile->monthly_income)->toBe('0.00')
        ->and($profile->income_verification)->toBeNull()
        ->and($scored->incomeCentavos)->toBeNull()
        ->and($scored->debtServiceRatioText())->toBeNull();
});

it('reads the latest assessment revision under a lock inside the loan transaction', function () {
    $application = lo4Ready($this);
    $locking = [];
    DB::listen(function ($query) use (&$locking): void {
        if (str_contains($query->sql, 'from `origination_assessments`') && str_ends_with($query->sql, 'for update')) {
            $locking[] = $query->sql;
        }
    });

    $this->actingAs($this->originationStaff(...LO4_HANDOFF))->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])->assertOk();

    expect($locking)->toHaveCount(1)
        ->and($locking[0])->toContain('order by `version` desc');
});

it('needs the assessment to run again when a different pending member is linked after an assessment without one', function () {
    // Unverified figures need further review with or without a member, so
    // only the member's record tells the two assessments apart.
    $application = $this->originationApplication();
    $this->originationDocuments($application);
    $staff = $this->originationStaff(...LO4_HANDOFF, ...['loan_origination:update']);
    $this->actingAs($staff)->postJson("/api/loan-origination/applications/{$application->id}/assessment/rerun")->assertCreated()->assertJsonPath('data.result', 'further_review');
    $other = Borrower::factory()->create(['status' => 'pending', 'branch_id' => Branch::query()->value('id'), 'first_name' => 'Pedro', 'last_name' => 'Reyes']);
    $this->actingAs($staff)->postJson("/api/loan-origination/applications/{$application->id}/link-member", ['borrower_id' => $other->id, 'version' => $application->fresh()->version])->assertOk();

    $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->fresh()->version])
        ->assertUnprocessable()
        ->assertJsonPath('errors.assessment.0', 'The application changed after it was assessed. Run the assessment again.');

    expect(Loan::query()->count())->toBe(0)
        ->and(Document::query()->where('documentable_type', Borrower::class)->where('documentable_id', $other->id)->count())->toBe(0);
});

it('files the ID under its own type, not as the member\'s valid ID, when the assessment lists differences from the linked member\'s record', function () {
    $member = Borrower::factory()->create(['status' => 'active', 'branch_id' => Branch::query()->value('id'), 'first_name' => 'Pedro', 'last_name' => 'Reyes']);
    $application = lo4Ready($this, [], '40000', ['borrower_id' => $member->id]);
    expect(OriginationAssessment::currentFor($application)->conflicts)->toContain(CapacityAssessor::FIRST_NAME_DIFFERS);

    $this->actingAs($this->originationStaff(...LO4_HANDOFF))->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])->assertOk();

    $types = Document::query()->where('documentable_type', Borrower::class)->where('documentable_id', $member->id)->orderBy('type')->pluck('type')->all();
    expect($types)->toBe(['government_id', 'proof_of_address', 'proof_of_income'])
        ->and(app(ValidIdService::class)->grouped($member))->toHaveCount(0);
});

it('refuses, in words, a hand-off that creates the member without a verified ID recording its type and number', function () {
    $types = collect(OriginationSetting::current()->document_types)->map(fn (array $type) => [...$type, 'required' => $type['type'] === 'government_id' ? false : $type['required']])->all();
    OriginationSetting::current()->forceFill(['document_types' => $types])->save();
    $application = lo4Ready($this);
    DB::table('origination_documents')->where('application_id', $application->id)->where('type', 'government_id')->update(['status' => 'rejected']);

    $this->actingAs($this->originationStaff(...LO4_HANDOFF))->postJson(lo4HandOffUrl($application->id), ['version' => $application->fresh()->version])
        ->assertUnprocessable()
        ->assertJsonPath('errors.documents.0', 'Approving the new member\'s registration needs a valid ID. Verify the applicant\'s ID, with its ID type and number, before submitting for approval.');

    expect(Loan::query()->count())->toBe(0)
        ->and($application->fresh()->borrower_id)->toBeNull();
});

it('answers 409 while another hand-off of the same application holds its lock, and creates nothing', function () {
    $application = lo4Ready($this);
    $lock = Cache::lock(ApplicationHandOff::lockKey($application->id), 60);
    expect($lock->get())->toBeTrue();

    $this->actingAs($this->originationStaff(...LO4_HANDOFF))->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])
        ->assertStatus(409)
        ->assertJsonPath('message', ApplicationHandOff::BUSY);

    $lock->release();
    expect(Loan::query()->count())->toBe(0)
        ->and(Borrower::query()->where('status', 'pending')->count())->toBe(0)
        ->and($application->fresh()->borrower_id)->toBeNull();
});

it('holds the member\'s lock around the loan, as a new loan does, so a member being transferred answers 409', function () {
    $member = Borrower::factory()->create(['status' => 'active', 'branch_id' => Branch::query()->value('id')]);
    $application = lo4Ready($this, [], '40000', ['borrower_id' => $member->id]);
    $lock = Cache::lock(MemberLock::key($member->id), 60);
    expect($lock->get())->toBeTrue();

    $this->actingAs($this->originationStaff(...LO4_HANDOFF))->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])
        ->assertStatus(409)
        ->assertJsonPath('message', MemberLock::BUSY);

    $lock->release();
    expect(Loan::query()->count())->toBe(0)
        ->and($application->fresh()->status)->toBe('under_assessment');
});

it('re-checks the required documents inside the loan transaction, so one rejected meanwhile stops the hand-off', function () {
    $application = lo4Ready($this);
    Loan::created(function () use ($application): void {
        DB::table('origination_documents')->where('application_id', $application->id)->where('type', 'proof_of_income')->update(['status' => 'rejected']);
    });

    $this->actingAs($this->originationStaff(...LO4_HANDOFF))->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])
        ->assertUnprocessable()
        ->assertJsonPath('errors.documents.0', 'Verify these documents first: Proof of income.');

    expect(Loan::query()->count())->toBe(0)
        ->and($application->fresh()->status)->toBe('under_assessment');
});

it('answers 409 when the assessment was run again while the hand-off was under way', function () {
    $application = lo4Ready($this);
    $staff = $this->originationStaff(...LO4_HANDOFF);
    Loan::created(function () use ($application, $staff): void {
        app(AssessmentService::class)->run($application->fresh(), $staff, []);
    });

    $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])->assertStatus(409);

    expect(Loan::query()->count())->toBe(0)
        ->and($application->fresh()->status)->toBe('under_assessment');
});

describe('an applicant who is already a member', function () {
    /** A member with the applicant's own name and birthdate. */
    function lo4ExistingMember(OriginationApplication $application): Borrower
    {
        $personal = $application->draft['personal'];

        return Borrower::factory()->create([
            'status' => 'active', 'branch_id' => Branch::query()->value('id'), 'first_name' => $personal['first_name'],
            'middle_name' => $personal['middle_name'], 'last_name' => $personal['last_name'], 'birthdate' => $personal['date_of_birth'],
        ]);
    }

    it('is never made a second member: refused in words that point to linking them, whatever force the applicant\'s draft carries', function () {
        $application = lo4Ready($this);
        $existing = lo4ExistingMember($application);
        $draft = $application->draft;
        $application->forceFill(['draft' => [...$draft, 'force' => true, 'personal' => [...$draft['personal'], 'force' => true]]])->save();

        $this->actingAs($this->originationStaff(...LO4_HANDOFF))->postJson(lo4HandOffUrl($application->id), ['version' => $application->fresh()->version])
            ->assertUnprocessable()
            ->assertJsonPath('errors.member.0', "The applicant matches {$existing->full_name} ({$existing->borrower_code}), already a member. Link that member to the application, or create a new member anyway.");

        expect(Borrower::query()->where('last_name', $existing->last_name)->count())->toBe(1)
            ->and(Loan::query()->count())->toBe(0)
            ->and($application->fresh()->borrower_id)->toBeNull();
    });

    it('names no one to staff who can\'t see the member\'s branch', function () {
        $application = lo4Ready($this);
        $existing = lo4ExistingMember($application);
        DB::table('borrowers')->where('id', $existing->id)->update(['branch_id' => Branch::factory()->create()->id]);

        $this->actingAs($this->originationStaff(...LO4_HANDOFF))->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])
            ->assertUnprocessable()
            ->assertJsonPath('errors.member.0', 'The applicant matches a member already on record. Link that member to the application, or create a new member anyway.');
    });

    it('makes a new member when staff choose "Create anyway", and checks again under the newest member\'s lock otherwise, as POST /borrowers does', function () {
        $application = lo4Ready($this);
        $existing = lo4ExistingMember($application);
        $staff = $this->originationStaff(...LO4_HANDOFF);
        $locked = [];
        DB::listen(function ($query) use (&$locked): void {
            // The duplicate check's lock reads only the id; the member number's reads the row.
            if (str_starts_with($query->sql, 'select `id` from `borrowers`') && str_ends_with($query->sql, 'order by `id` desc limit 1 for update')) {
                $locked[] = $query->sql;
            }
        });

        $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->version, 'force_new_member' => true])->assertOk();
        $created = Borrower::query()->findOrFail($application->fresh()->borrower_id);

        $other = lo4Ready($this);
        $this->actingAs($staff)->postJson(lo4HandOffUrl($other->id), ['version' => $other->version])->assertOk();

        expect($created->id)->not->toBe($existing->id)
            ->and($created->status)->toBe('pending')
            ->and($locked)->toHaveCount(1);
    });
});

describe('linking a member (LO-B3\'s route)', function () {
    it('needs the assessment to run again before the hand-off once a member is linked', function () {
        $member = Borrower::factory()->create(['status' => 'active', 'branch_id' => Branch::query()->value('id')]);
        $application = lo4Ready($this);
        $staff = $this->originationStaff('loan_origination:view', 'loan_origination:update', 'borrowers:view', 'loan_origination:convert', 'loans:create');

        $this->actingAs($staff)->postJson("/api/loan-origination/applications/{$application->id}/link-member", ['borrower_id' => $member->id, 'version' => $application->version])
            ->assertOk()->assertJsonPath('data.linked_borrower_id', $member->id);

        $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->fresh()->version])
            ->assertUnprocessable()
            ->assertJsonPath('errors.assessment.0', 'The application changed after it was assessed. Run the assessment again.');
    });

    it('refuses a rejected registration', function () {
        $application = lo4Ready($this);
        $rejected = Borrower::factory()->create(['status' => 'rejected', 'branch_id' => Branch::query()->value('id')]);

        $this->actingAs($this->originationStaff('loan_origination:view', 'loan_origination:update', 'borrowers:view'))
            ->postJson("/api/loan-origination/applications/{$application->id}/link-member", ['borrower_id' => $rejected->id, 'version' => $application->version])
            ->assertUnprocessable()->assertJsonPath('errors.borrower_id.0', 'That member can\'t be linked to this application.');

        expect($application->fresh()->borrower_id)->toBeNull();
    });
});
