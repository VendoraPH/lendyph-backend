<?php

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Loan;
use Tests\TestCase;

require_once __DIR__.'/StaffFixtures.php';

/*
 * LO-B3: member matches (origination decision 13). Suggestions for staff
 * only, never linked automatically: same full name, same mobile (last 10
 * digits) or same email, among members the caller can see. They need
 * `borrowers:view`. Linking one needs `loan_origination:update`,
 * `borrowers:view` and the member's branch; former members are refused, and
 * nothing changes once the application has been handed off.
 */
uses(TestCase::class);

beforeEach(function () {
    $this->main = Branch::query()->orderBy('id')->firstOrFail();
    $this->other = Branch::factory()->create(['name' => 'North Branch', 'code' => 'NB']);
    $this->application = lo3Application('submitted', ['mobile' => '09171234567', 'email' => 'Juana@Example.test']);
});

it('suggests members by full name, mobile and email, with the reason in words', function () {
    $byName = Borrower::factory()->create(['first_name' => 'Juana', 'middle_name' => null, 'last_name' => 'Dela Cruz', 'branch_id' => $this->main->id, 'status' => 'active']);
    $byMobile = Borrower::factory()->create(['first_name' => 'Ana', 'last_name' => 'Lim', 'contact_number' => '+63 917 123 4567', 'branch_id' => $this->main->id, 'status' => 'active']);
    $byEmail = Borrower::factory()->create(['first_name' => 'Rosa', 'last_name' => 'Uy', 'email' => 'juana@example.TEST', 'branch_id' => $this->main->id, 'status' => 'active']);
    Borrower::factory()->create(['first_name' => 'Juana', 'middle_name' => null, 'last_name' => 'Dela Cruz', 'branch_id' => $this->other->id, 'status' => 'active']);

    $user = lo3Staff($this->main, 'loan_origination:view', 'borrowers:view');
    $matches = collect($this->actingAs($user)->getJson("/api/loan-origination/applications/{$this->application->id}/member-matches")->assertOk()->json('data'));

    expect($matches->pluck('id')->all())->toEqualCanonicalizing([$byName->id, $byMobile->id, $byEmail->id])
        ->and($matches->firstWhere('id', $byName->id)['reason'])->toBe('Same full name')
        ->and($matches->firstWhere('id', $byMobile->id)['reason'])->toBe('Same mobile number')
        ->and($matches->firstWhere('id', $byEmail->id)['reason'])->toBe('Same email address')
        ->and(array_keys($matches->first()))->toEqualCanonicalizing(['id', 'name', 'member_number', 'status', 'reason']);
});

it('hides matches without borrowers:view', function () {
    Borrower::factory()->create(['first_name' => 'Juana', 'middle_name' => null, 'last_name' => 'Dela Cruz', 'branch_id' => $this->main->id]);

    $this->actingAs(lo3Staff($this->main, 'loan_origination:view'))
        ->getJson("/api/loan-origination/applications/{$this->application->id}/member-matches")->assertForbidden();

    $detail = $this->actingAs(lo3Staff($this->main, 'loan_origination:view'))
        ->getJson("/api/loan-origination/applications/{$this->application->id}")->assertOk();
    expect($detail->json('data.possible_member_matches'))->toBe([])
        ->and($detail->json('data.member_matches_hidden'))->toBeTrue();
});

it('links and unlinks a member the caller can see, audited', function () {
    $member = Borrower::factory()->create(['branch_id' => $this->main->id, 'status' => 'active']);
    $user = lo3Staff($this->main, 'loan_origination:update', 'borrowers:view');

    $this->actingAs($user)->postJson("/api/loan-origination/applications/{$this->application->id}/link-member", ['borrower_id' => $member->id, 'version' => $this->application->version])
        ->assertOk()->assertJsonPath('data.linked_borrower_id', $member->id);

    $fresh = $this->application->fresh();
    expect($fresh->borrower_id)->toBe($member->id)
        ->and(AuditLog::query()->where('action', 'origination_member_linked')->where('auditable_id', $fresh->id)->exists())->toBeTrue();

    $this->actingAs($user)->postJson("/api/loan-origination/applications/{$fresh->id}/link-member", ['borrower_id' => null, 'version' => $fresh->version])
        ->assertOk()->assertJsonPath('data.linked_borrower_id', null);
});

it('refuses another branch\'s member, a former member, a missing permission, and a handed-off application', function () {
    $user = lo3Staff($this->main, 'loan_origination:update', 'borrowers:view');
    $elsewhere = Borrower::factory()->create(['branch_id' => $this->other->id, 'status' => 'active']);
    $withdrawn = Borrower::factory()->create(['branch_id' => $this->main->id, 'status' => 'withdrawn']);
    $url = "/api/loan-origination/applications/{$this->application->id}/link-member";

    $this->actingAs($user)->postJson($url, ['borrower_id' => $elsewhere->id, 'version' => $this->application->version])->assertUnprocessable();
    $this->actingAs($user)->postJson($url, ['borrower_id' => $withdrawn->id, 'version' => $this->application->version])
        ->assertUnprocessable()->assertJsonPath('errors.borrower_id.0', 'A withdrawn or deceased member can\'t apply for a new loan, so they can\'t be linked.');
    $this->actingAs(lo3Staff($this->main, 'loan_origination:update'))
        ->postJson($url, ['borrower_id' => $elsewhere->id, 'version' => $this->application->version])->assertForbidden();

    $loan = Loan::factory()->create();
    $handedOff = lo3Application('pending_approval', ['loan_id' => $loan->id]);
    $member = Borrower::factory()->create(['branch_id' => $this->main->id, 'status' => 'active']);
    $this->actingAs($user)->postJson("/api/loan-origination/applications/{$handedOff->id}/link-member", ['borrower_id' => $member->id, 'version' => $handedOff->version])
        ->assertUnprocessable();

    expect($this->application->fresh()->borrower_id)->toBeNull();
});
