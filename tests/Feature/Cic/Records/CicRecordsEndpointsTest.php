<?php

use App\Models\CicCapture;
use App\Models\LoanProduct;
use App\Services\Cic\Capture\CicCaptureService;
use App\Services\Cic\CicAccess;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cic\Records\CicRecordsFixtures;
use Tests\TestCase;

uses(TestCase::class, CicRecordsFixtures::class);

/*
 * The records slice's endpoints: readiness, records, one record, issues and
 * captures. Member names and field values need borrowers:view; everything
 * needs cic:view (or cic:prepare to capture) and head-office access.
 */

beforeEach(function () {
    $this->cicOn();
    $this->viewer = $this->cicUser([CicAccess::VIEW, 'branches:view_all']);
    $this->reader = $this->cicUser([CicAccess::VIEW, 'branches:view_all', 'borrowers:view']);
    $this->preparer = $this->cicUser([CicAccess::VIEW, CicAccess::PREPARE, 'branches:view_all', 'borrowers:view']);
});

it('serves readiness built live, with counts, top issues and unmapped products, and no personal data', function () {
    $member = $this->cicMember(['first_name' => 'Rosalinda', 'birthdate' => null]);
    $this->cicLoan($member, LoanProduct::factory()->create(['name' => 'Emergency Loan']));
    $this->cicLoan();

    $response = $this->actingAs($this->reader)->getJson('/api/cic/readiness?reference_date=2026-11-30')->assertOk();

    $response->assertJsonPath('data.reference_date', '2026-11-30')
        ->assertJsonPath('data.provider_code_set', true)
        ->assertJsonPath('data.blocking', true)
        ->assertJsonPath('data.record_types.ID.records', 2)
        ->assertJsonPath('data.record_types.ID.with_errors', 1)
        ->assertJsonPath('data.record_types.CI.records', 2)
        ->assertJsonPath('data.record_types.CI.with_errors', 1)
        ->assertJsonPath('data.record_types.CI.ready', 1)
        ->assertJsonPath('data.totals.records', 4)
        ->assertJsonPath('data.unmapped_products.0.name', 'Emergency Loan')
        ->assertJsonPath('data.unmapped_products.0.contracts', 1)
        ->assertJsonPath('data.last_capture', null)
        ->assertJsonStructure(['data' => ['top_issues' => [['record_type', 'field_id', 'field_name', 'classification', 'code', 'severity', 'count', 'message']], 'file_issues']]);

    expect($response->getContent())->not->toContain('Rosalinda')->not->toContain($member->borrower_code);
});

it('pages records with issue counts, naming members only with borrowers:view', function () {
    $member = $this->cicMember(['first_name' => 'Rosalinda']);
    $loan = $this->cicLoan($member);

    $masked = $this->actingAs($this->viewer)->getJson('/api/cic/records?record_type=CI')->assertOk();
    $masked->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 25)->assertJsonPath('meta.source', 'live')
        ->assertJsonPath('data.0.record_type', 'CI')
        ->assertJsonPath('data.0.record_key', $loan->loan_account_number);
    expect($masked->json('data.0'))->toHaveKeys(['errors', 'warnings'])->not->toHaveKey('member')
        ->and($masked->getContent())->not->toContain('Rosalinda');

    $this->actingAs($this->reader)->getJson('/api/cic/records')->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.record_type', 'ID')
        ->assertJsonPath('data.0.member.name', 'Rosalinda Santos Cruz')
        ->assertJsonPath('data.0.member.code', $member->borrower_code);
});

it('shows one record\'s issues, and its field values only with borrowers:view', function () {
    $member = $this->cicMember(['birthdate' => null]);
    $this->cicLoan($member);
    $url = "/api/cic/records/ID/{$member->borrower_code}";

    $masked = $this->actingAs($this->viewer)->getJson($url)->assertOk()
        ->assertJsonPath('data.errors', 1)
        ->assertJsonPath('data.issues.0.field_id', 'ID14')
        ->assertJsonPath('data.issues.0.field_name', 'Date of Birth')
        ->assertJsonPath('data.issues.0.classification', 'M')
        ->assertJsonPath('data.fields.6.field_id', 'ID7');
    expect($masked->json('data.fields.6'))->not->toHaveKey('value')
        ->and($masked->json('data'))->not->toHaveKey('member')
        ->and($masked->getContent())->not->toContain('Maria');

    $this->actingAs($this->reader)->getJson($url)->assertOk()
        ->assertJsonPath('data.fields.6.value', 'Maria')
        ->assertJsonPath('data.member.code', $member->borrower_code);

    $this->actingAs($this->reader)->getJson('/api/cic/records/ID/BRW-999999')->assertNotFound();
});

it('pages issues errors first, filtered, naming members only with borrowers:view', function () {
    $member = $this->cicMember(['first_name' => 'Rosalinda', 'birthdate' => null]);
    $this->cicLoan($member);

    $masked = $this->actingAs($this->viewer)->getJson('/api/cic/issues?severity=error&record_type=ID')->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.errors', 1)
        ->assertJsonPath('data.0.code', 'MISSING_MANDATORY')
        ->assertJsonPath('data.0.record_key', $member->borrower_code);
    expect($masked->json('data.0'))->not->toHaveKey('member')->and($masked->getContent())->not->toContain('Rosalinda');

    $this->actingAs($this->reader)->getJson('/api/cic/issues?severity=error&record_type=ID')->assertOk()
        ->assertJsonPath('data.0.member.name', 'Rosalinda Santos Cruz');

    $this->actingAs($this->viewer)->getJson('/api/cic/issues?severity=info')->assertUnprocessable()
        ->assertJsonPath('errors.severity.0', 'The severity must be error or warning.');
});

it('refuses without head-office access or the permission, in words, and takes captures only with cic:prepare', function () {
    $this->actingAs($this->cicUser([CicAccess::VIEW, 'borrowers:view']))->getJson('/api/cic/records')->assertForbidden()
        ->assertJsonPath('message', CicAccess::VIEW_ALL_REFUSAL);
    $this->actingAs($this->cicUser(['branches:view_all']))->getJson('/api/cic/readiness')->assertForbidden()
        ->assertJsonPath('message', 'You don\'t have access to view CIC reporting.');
    $this->actingAs($this->reader)->postJson('/api/cic/captures', ['kind' => 'test'])->assertForbidden()
        ->assertJsonPath('message', 'You don\'t have access to prepare CIC files.');
});

it('answers 404 on every records route while CIC is off', function () {
    config(['cic.enabled' => false]);

    foreach (['/api/cic/readiness', '/api/cic/records', '/api/cic/records/ID/BRW-000001', '/api/cic/issues', '/api/cic/captures', '/api/cic/captures/1'] as $url) {
        $this->actingAs($this->reader)->getJson($url)->assertNotFound();
    }
});

it('lists captures and shows one, with counts, status and checksum', function () {
    $capture = CicCapture::factory()->monthEnd('2026-10-31')->completeWithRecords(2, 1)->create();

    $this->actingAs($this->viewer)->getJson('/api/cic/captures')->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $capture->id)
        ->assertJsonPath('data.0.reference_date', '2026-10-31')
        ->assertJsonPath('data.0.kind', 'month_end')
        ->assertJsonPath('data.0.status', 'complete')
        ->assertJsonPath('data.0.subject_count', 2)
        ->assertJsonPath('data.0.created_by', null);

    $this->actingAs($this->viewer)->getJson("/api/cic/captures/{$capture->id}")->assertOk()
        ->assertJsonPath('data.checksum', $capture->fresh()->checksum);
    $this->actingAs($this->viewer)->getJson('/api/cic/captures/999999')->assertNotFound();
});

it('refuses a test capture while test captures are off, in words', function () {
    $this->actingAs($this->preparer)->postJson('/api/cic/captures', ['kind' => 'test'])->assertUnprocessable()
        ->assertJsonPath('errors.kind.0', 'Test captures are off on this server. Month-end figures are captured by the schedule at 23:58 on the last day of each month.');
    $this->actingAs($this->preparer)->postJson('/api/cic/captures', ['kind' => 'month_end'])->assertUnprocessable()
        ->assertJsonPath('errors.kind.0', 'Choose a test or correction capture.');
    $this->actingAs($this->preparer)->postJson('/api/cic/captures', ['kind' => 'correction'])->assertUnprocessable()
        ->assertJsonPath('errors.corrects_capture_id.0', 'Choose the capture to correct.');
});

it('answers a capture that couldn\'t finish with 422 and its message in words', function () {
    config(['cic.test_captures' => true]);
    $loan = $this->cicLoan();
    DB::table('borrowers')->where('id', $loan->borrower_id)->update(['borrower_code' => '']);

    $this->actingAs($this->preparer)->postJson('/api/cic/captures', ['kind' => 'test'])
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'capture_failed')
        ->assertJsonPath('message', CicCaptureService::FAILURE)
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.error', CicCaptureService::FAILURE);
});

it('builds, captures and reports issues end to end', function () {
    config(['cic.test_captures' => true]);
    $mapped = $this->cicLoan();
    $unmapped = $this->cicLoan(product: LoanProduct::factory()->create(['name' => 'Emergency Loan']));

    $capture = $this->actingAs($this->preparer)->postJson('/api/cic/captures', ['kind' => 'test'])->assertCreated()
        ->assertJsonPath('data.kind', 'test')
        ->assertJsonPath('data.status', 'complete')
        ->assertJsonPath('data.subject_count', 2)
        ->assertJsonPath('data.contract_count', 2)
        ->assertJsonPath('data.created_by.id', $this->preparer->id)
        ->json('data');

    $issues = $this->actingAs($this->reader)->getJson("/api/cic/issues?capture_id={$capture['id']}&severity=error")->assertOk()
        ->assertJsonPath('meta.source', 'capture')
        ->assertJsonPath('meta.capture_id', $capture['id']);

    expect(collect($issues->json('data'))->where('code', 'UNMAPPED_PRODUCT')->pluck('record_key')->all())->toBe([$unmapped->loan_account_number]);

    $this->actingAs($this->reader)->getJson("/api/cic/records?capture_id={$capture['id']}&record_type=CI")->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.reference_date', '2026-11-30');

    $this->actingAs($this->reader)->getJson("/api/cic/records/CI/{$mapped->loan_account_number}?capture_id={$capture['id']}")->assertOk()
        ->assertJsonPath('data.source', 'capture')
        ->assertJsonPath('data.errors', 0);

    $this->actingAs($this->reader)->getJson('/api/cic/readiness')->assertOk()
        ->assertJsonPath('data.last_capture.id', $capture['id']);
});
