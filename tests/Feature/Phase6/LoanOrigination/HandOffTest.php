<?php

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
use App\Models\OriginationStatusHistory;
use App\Models\ShareCapitalPledge;
use App\Services\CreditScoring\Capacity;
use App\Services\CreditScoring\Exposure;
use App\Services\CreditScoring\MemberCreditData;
use App\Services\LoanListTotals;
use App\Services\LoanService;
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
        ->and($scored->debtServiceRatioText())->toBe($assessment->figures['dti_ratio'])
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

it('sends an above-capacity or insufficient-information result to the Policy Exception chain with its explanation', function () {
    $application = lo4Ready($this, ['employment' => ['gross_monthly_income' => '9000']], '9000');

    $this->actingAs($this->originationStaff(...LO4_HANDOFF))->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])->assertOk();
    $loan = Loan::query()->findOrFail($application->fresh()->loan_id);

    expect($loan->policy_exception)->toBeTrue()
        ->and($loan->policy_exception_details)->toStartWith('Online application '.$application->reference_number.': Above capacity')
        ->and($loan->policy_exception_details)->toContain('debt-to-income ratio');
});

it('copies the verified documents to the member\'s documents', function () {
    $application = lo4Ready($this);
    $this->actingAs($this->originationStaff(...LO4_HANDOFF))->postJson(lo4HandOffUrl($application->id), ['version' => $application->version])->assertOk();
    $memberId = $application->fresh()->borrower_id;

    $documents = Document::query()->where('documentable_type', Borrower::class)->where('documentable_id', $memberId)->orderBy('type')->get();

    expect($documents->pluck('type')->all())->toBe(['government_id', 'proof_of_address', 'proof_of_income'])
        ->and($documents->first()->label)->toBe('Valid government ID (online application '.$application->reference_number.')');
    foreach ($documents as $document) {
        Storage::disk('private')->assertExists($document->file_path);
        expect($document->file_path)->toStartWith("documents/{$document->type}/borrower/{$memberId}/");
    }
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

describe('linking a member', function () {
    it('links a visible member before the hand-off, needing borrowers:view, and the assessment must then run again', function () {
        $member = Borrower::factory()->create(['status' => 'active', 'branch_id' => Branch::query()->value('id')]);
        $application = lo4Ready($this);
        $staff = $this->originationStaff('loan_origination:view', 'loan_origination:update', 'borrowers:view', 'loan_origination:convert', 'loans:create');

        $this->actingAs($staff)->postJson("/api/loan-origination/applications/{$application->id}/link-member", ['borrower_id' => $member->id, 'version' => $application->version])
            ->assertOk()->assertJsonPath('data.borrower_id', $member->id);

        $this->actingAs($staff)->postJson(lo4HandOffUrl($application->id), ['version' => $application->fresh()->version])
            ->assertUnprocessable()
            ->assertJsonPath('errors.assessment.0', 'The application changed after it was assessed. Run the assessment again.');
    });

    it('refuses without borrowers:view, another branch\'s member, and a former or rejected member', function () {
        $application = lo4Ready($this);
        $url = "/api/loan-origination/applications/{$application->id}/link-member";
        $staff = $this->originationStaff('loan_origination:view', 'loan_origination:update', 'borrowers:view');

        $this->actingAs($this->originationStaff('loan_origination:view', 'loan_origination:update'))
            ->postJson($url, ['borrower_id' => Borrower::factory()->create()->id, 'version' => $application->version])
            ->assertForbidden()->assertJsonPath('message', 'Linking a member needs the permission to update online applications and the permission to view members.');

        $elsewhere = Borrower::factory()->create(['status' => 'active', 'branch_id' => Branch::factory()->create()->id]);
        $this->actingAs($staff)->postJson($url, ['borrower_id' => $elsewhere->id, 'version' => $application->version])
            ->assertUnprocessable()->assertJsonPath('errors.borrower_id.0', 'Choose a member in one of your branches.');

        $withdrawn = Borrower::factory()->create(['status' => 'withdrawn', 'branch_id' => Branch::query()->value('id')]);
        $this->actingAs($staff)->postJson($url, ['borrower_id' => $withdrawn->id, 'version' => $application->version])
            ->assertUnprocessable()->assertJsonValidationErrors(['borrower_id']);

        $rejected = Borrower::factory()->create(['status' => 'rejected', 'branch_id' => Branch::query()->value('id')]);
        $this->actingAs($staff)->postJson($url, ['borrower_id' => $rejected->id, 'version' => $application->version])
            ->assertUnprocessable()->assertJsonPath('errors.borrower_id.0', 'This member\'s registration was rejected, so they can\'t be linked.');

        expect($application->fresh()->borrower_id)->toBeNull();
    });

    it('unlinks with null, and refuses once handed off', function () {
        $member = Borrower::factory()->create(['status' => 'active', 'branch_id' => Branch::query()->value('id')]);
        $application = lo4Ready($this, [], '40000', ['borrower_id' => $member->id]);
        $staff = $this->originationStaff('loan_origination:view', 'loan_origination:update', 'borrowers:view');
        $url = "/api/loan-origination/applications/{$application->id}/link-member";

        $this->actingAs($staff)->postJson($url, ['borrower_id' => null, 'version' => $application->version])->assertOk()->assertJsonPath('data.borrower_id', null);

        DB::table('origination_applications')->where('id', $application->id)->update(['status' => 'pending_approval']);
        $this->actingAs($staff)->postJson($url, ['borrower_id' => $member->id, 'version' => $application->fresh()->version])
            ->assertUnprocessable()->assertJsonPath('errors.status.0', 'A member can be linked only before the application is submitted for approval.');
    });
});
