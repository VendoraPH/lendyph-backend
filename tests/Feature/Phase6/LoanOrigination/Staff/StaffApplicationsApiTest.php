<?php

use App\Models\Branch;
use App\Models\OriginationNote;
use App\Models\OriginationStatusHistory;
use Tests\TestCase;

require_once __DIR__.'/StaffFixtures.php';

/*
 * LO-B3: the staff applications list and detail (origination decision 16).
 * Staff see only their branches' applications; another branch's answers 404,
 * and an out-of-scope branch filter 403. Contacts and addresses need
 * `borrowers:view` and the application's branch, otherwise they're masked.
 * `meta.stats` holds the per-status counts and the total, so the browser
 * never adds them up.
 */
uses(TestCase::class);

beforeEach(function () {
    $this->main = Branch::query()->orderBy('id')->firstOrFail();
    $this->other = Branch::factory()->create(['name' => 'North Branch', 'code' => 'NB']);
});

it('lists only the caller\'s branches\' applications, with per-status counts and the total', function () {
    lo3Application('submitted');
    lo3Application('submitted');
    lo3Application('under_assessment');
    lo3Application('submitted', ['branch_id' => $this->other->id]);
    $user = lo3Staff($this->main, 'loan_origination:view');

    $response = $this->actingAs($user)->getJson('/api/loan-origination/applications?status=submitted')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta.stats.submitted'))->toBe(2)
        ->and($response->json('meta.stats.under_assessment'))->toBe(1)
        ->and($response->json('meta.stats.total'))->toBe(3)
        ->and($response->json('data.0.status_label'))->toBe('Submitted')
        ->and($response->json('data.0.branch_name'))->toBe($this->main->name);
});

it('refuses the list without loan_origination:view, and a branch filter outside the caller\'s branches', function () {
    $this->actingAs(lo3Staff($this->main, 'loans:view'))->getJson('/api/loan-origination/applications')->assertForbidden();

    $this->actingAs(lo3Staff($this->main, 'loan_origination:view'))
        ->getJson('/api/loan-origination/applications?branch_id='.$this->other->id)->assertForbidden();
});

it('masks the mobile unless the caller holds borrowers:view', function () {
    lo3Application('submitted', ['mobile' => '09171234567']);

    $masked = $this->actingAs(lo3Staff($this->main, 'loan_origination:view'))->getJson('/api/loan-origination/applications')->assertOk();
    expect($masked->json('data.0.mobile'))->not->toBe('09171234567')->toEndWith('67')->toContain('*');

    $plain = $this->actingAs(lo3Staff($this->main, 'loan_origination:view', 'borrowers:view'))->getJson('/api/loan-origination/applications')->assertOk();
    expect($plain->json('data.0.mobile'))->toBe('09171234567');
});

it('searches by reference and name, and by mobile only for callers who may see contacts', function () {
    $a = lo3Application('submitted', ['applicant_name' => 'Pedro Santos', 'mobile' => '09179998888']);
    lo3Application('submitted', ['applicant_name' => 'Maria Reyes']);

    $viewer = lo3Staff($this->main, 'loan_origination:view');
    expect($this->actingAs($viewer)->getJson('/api/loan-origination/applications?search=santos')->json('data.*.id'))->toBe([$a->id])
        ->and($this->actingAs($viewer)->getJson('/api/loan-origination/applications?search='.$a->reference_number)->json('data.*.id'))->toBe([$a->id])
        ->and($this->actingAs($viewer)->getJson('/api/loan-origination/applications?search=0917-999-8888')->json('data'))->toBe([]);

    $contacts = lo3Staff($this->main, 'loan_origination:view', 'borrowers:view');
    expect($this->actingAs($contacts)->getJson('/api/loan-origination/applications?search=0917-999-8888')->json('data.*.id'))->toBe([$a->id]);
});

it('shows the detail with notes, history, version and the staff moves the caller may make', function () {
    $application = lo3Application('submitted');
    $author = lo3Staff($this->main, 'loan_origination:update');
    OriginationNote::query()->create(['application_id' => $application->id, 'user_id' => $author->id, 'body' => 'Called the applicant.']);
    OriginationStatusHistory::query()->create(['application_id' => $application->id, 'from_status' => 'draft', 'to_status' => 'submitted', 'actor_type' => 'applicant']);

    $user = lo3Staff($this->main, 'loan_origination:view', 'loan_origination:update');
    $response = $this->actingAs($user)->getJson("/api/loan-origination/applications/{$application->id}")->assertOk();

    expect($response->json('data.notes.0.body'))->toBe('Called the applicant.')
        ->and($response->json('data.notes.0.author_name'))->toBe($author->full_name)
        ->and($response->json('data.history.0.to_status'))->toBe('submitted')
        ->and($response->json('data.history.0.changed_by_name'))->toBe('Applicant')
        ->and($response->json('data.version'))->toBe($application->version)
        ->and($response->json('data.allowed_transitions'))->toEqualCanonicalizing(['document_verification', 'additional_info_required', 'withdrawn'])
        ->and($response->json('data.declared_totals.monthly_expenses'))->toBe('0.00')
        ->and($response->json('data.applicant_messages'))->toBe([]);
});

it('masks contacts and address lines on the detail without borrowers:view', function () {
    $application = lo3Application('submitted');

    $masked = $this->actingAs(lo3Staff($this->main, 'loan_origination:view'))
        ->getJson("/api/loan-origination/applications/{$application->id}")->assertOk();

    expect($masked->json('data.email'))->not->toBe('juana@example.test')
        ->and($masked->json('data.draft.personal.street_address'))->toBe('On file')
        ->and($masked->json('data.draft.personal.barangay'))->toBe('On file')
        ->and($masked->json('data.draft.personal.city'))->toBe('Cebu City')
        ->and($masked->json('data.contacts_hidden'))->toBeTrue();

    $plain = $this->actingAs(lo3Staff($this->main, 'loan_origination:view', 'borrowers:view'))
        ->getJson("/api/loan-origination/applications/{$application->id}")->assertOk();

    expect($plain->json('data.email'))->toBe('juana@example.test')
        ->and($plain->json('data.draft.personal.street_address'))->toBe('12 Rizal Street')
        ->and($plain->json('data.contacts_hidden'))->toBeFalse();
});

it('answers 404 for another branch\'s application', function () {
    $application = lo3Application('submitted', ['branch_id' => $this->other->id]);

    $this->actingAs(lo3Staff($this->main, 'loan_origination:view'))
        ->getJson("/api/loan-origination/applications/{$application->id}")->assertNotFound();
});
