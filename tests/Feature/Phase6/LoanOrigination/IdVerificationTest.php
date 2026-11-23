<?php

use App\Models\ApprovalWorkflowSetting;
use App\Models\Borrower;
use App\Models\Loan;
use App\Models\OriginationDocument;
use App\Models\User;
use App\Services\ValidIdService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Phase6\LoanOrigination\OriginationFixtures;
use Tests\TestCase;

/*
 * LO-B4 review: an applicant's ID becomes the member's valid ID at the
 * hand-off, which approving their registration needs. So verifying an ID
 * (LO-B3's verify) records its ID type, one of the member valid ID types,
 * and its number, encrypted; the hand-off files them on the member's valid
 * ID. Other documents verify as before.
 */
uses(TestCase::class, OriginationFixtures::class);

beforeEach(function () {
    $this->pinOriginationToday();
    Storage::fake('private');
});

function lo4DocumentOf(int $applicationId, string $type): OriginationDocument
{
    return OriginationDocument::query()->where('application_id', $applicationId)->where('type', $type)->sole();
}

it('needs the ID type and number to verify an ID, in words, and keeps them encrypted', function () {
    $application = $this->originationApplication('under_assessment');
    $this->originationDocuments($application, 'pending_verification');
    $id = lo4DocumentOf($application->id, 'government_id');
    $staff = $this->originationStaff('loan_origination:view', 'loan_origination:update', 'borrowers:view');
    $url = "/api/loan-origination/documents/{$id->id}/verify";

    $this->actingAs($staff)->postJson($url, ['notes' => 'Clear.'])->assertUnprocessable()
        ->assertJsonPath('errors.id_type.0', 'Choose the ID type.')
        ->assertJsonPath('errors.id_number.0', 'Enter the ID number.');
    $this->actingAs($staff)->postJson($url, ['id_type' => 'school_id', 'id_number' => 'S-1'])->assertUnprocessable()
        ->assertJsonPath('errors.id_type.0', 'Choose the ID type from the list.');
    $this->actingAs($staff)->postJson($url, ['id_type' => 'others', 'id_number' => 'S-1'])->assertUnprocessable()
        ->assertJsonPath('errors.custom_type_name.0', 'Enter the ID type\'s name.');
    expect($id->fresh()->status)->toBe('pending_verification');

    $this->actingAs($staff)->postJson($url, ['id_type' => 'philippine_id', 'id_number' => ' 1234-5678-9012-3456 ', 'notes' => 'Clear.'])->assertOk()
        ->assertJsonPath('data.status', 'verified')
        ->assertJsonPath('data.id_document', true)
        ->assertJsonPath('data.id_type', 'philippine_id')
        ->assertJsonPath('data.id_type_label', 'Philippine National ID (PhilSys)')
        ->assertJsonPath('data.id_number', '1234-5678-9012-3456');

    expect($id->fresh()->id_details)->toBe(['type' => 'philippine_id', 'custom_type_name' => null, 'id_number' => '1234-5678-9012-3456'])
        ->and((string) DB::table('origination_documents')->where('id', $id->id)->value('id_details'))->not->toContain('1234');
});

it('keeps the name of an "Others" ID type, shows the number only with the contacts rule, and verifies other documents without ID details', function () {
    $application = $this->originationApplication('under_assessment');
    $this->originationDocuments($application, 'pending_verification');
    $id = lo4DocumentOf($application->id, 'government_id');
    $income = lo4DocumentOf($application->id, 'proof_of_income');

    $this->actingAs($this->originationStaff('loan_origination:view', 'loan_origination:update'))
        ->postJson("/api/loan-origination/documents/{$id->id}/verify", ['id_type' => 'others', 'custom_type_name' => 'Barangay ID', 'id_number' => 'BRGY-77'])->assertOk()
        ->assertJsonPath('data.id_type', 'others')
        ->assertJsonPath('data.id_type_label', 'Others')
        ->assertJsonPath('data.id_number', null);
    $this->actingAs($this->originationStaff('loan_origination:view', 'loan_origination:update', 'borrowers:view'))
        ->postJson("/api/loan-origination/documents/{$income->id}/verify", ['notes' => 'Payslip matches.', 'id_type' => 'passport', 'id_number' => 'P1'])->assertOk()
        ->assertJsonPath('data.id_document', false)
        ->assertJsonPath('data.id_type', null);

    expect($id->fresh()->id_details)->toBe(['type' => 'others', 'custom_type_name' => 'Barangay ID', 'id_number' => 'BRGY-77'])
        ->and($income->fresh()->id_details)->toBeNull();
});

it('clears an ID\'s recorded details when it is rejected or a replacement is asked for', function (string $decision) {
    $application = $this->originationApplication('under_assessment');
    $this->originationDocuments($application);
    $id = lo4DocumentOf($application->id, 'government_id');
    expect($id->id_details)->not->toBeNull();

    $this->actingAs($this->originationStaff('loan_origination:view', 'loan_origination:update'))
        ->postJson("/api/loan-origination/documents/{$id->id}/{$decision}", ['reason' => 'The photo is cut off.'])->assertOk();

    expect($id->fresh()->id_details)->toBeNull();
})->with(['reject', 'request-replacement']);

it('hands off an applicant whose ID was verified with its type and number, so the registration is approved and the loan released', function () {
    ApprovalWorkflowSetting::query()->updateOrCreate(['type' => ApprovalWorkflowSetting::TYPE_NORMAL], ['steps' => [
        ['id' => 'submit', 'name' => 'Submit', 'role' => 'super_admin', 'kind' => 'submit'],
        ['id' => 'release', 'name' => 'Release', 'role' => 'super_admin', 'kind' => 'release'],
    ]]);
    $application = $this->originationApplication('under_assessment');
    $this->originationDocuments($application, 'pending_verification');
    $admin = User::query()->where('username', 'super_admin')->firstOrFail();

    foreach (OriginationDocument::query()->where('application_id', $application->id)->get() as $document) {
        $body = $document->type === 'government_id' ? ['id_type' => 'umid', 'id_number' => 'CRN-0111-2222333-4'] : ['notes' => 'Checked.'];
        $this->actingAs($admin)->postJson("/api/loan-origination/documents/{$document->id}/verify", $body)->assertOk();
    }
    $this->actingAs($admin)->postJson("/api/loan-origination/applications/{$application->id}/assessment/rerun", ['verified_monthly_income' => '40000', 'verified_debt_payments' => '1500'])->assertCreated();
    $this->actingAs($admin)->postJson("/api/loan-origination/applications/{$application->id}/submit-for-approval", ['version' => $application->fresh()->version])->assertOk();

    $application->refresh();
    $member = Borrower::query()->findOrFail($application->borrower_id);
    expect(app(ValidIdService::class)->grouped($member)->sole())->toMatchArray(['type' => 'umid', 'custom_type_name' => null, 'id_number' => 'CRN-0111-2222333-4']);

    $this->actingAs($admin)->patchJson("/api/borrowers/{$member->id}/approve-registration")->assertOk()->assertJsonPath('data.status', 'active');
    $this->actingAs($admin)->patchJson("/api/loans/{$application->loan_id}/release")->assertOk();

    expect(Loan::query()->findOrFail($application->loan_id)->status)->toBe('released')
        ->and($application->fresh()->status)->toBe('converted');
});
