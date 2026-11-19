<?php

use App\Models\OriginationApplication;
use App\Models\OriginationStatusHistory;
use App\Models\User;
use App\Services\LoanOrigination\ApplicationTransitions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/*
 * LO-B1: the server's transition table (origination audit §3, decisions 1
 * and 18). "Submit for approval" is the hand-off, so no staff move reaches
 * For Approval; once the loan exists the application follows it. Rejecting
 * needs `loans:reject`.
 */
uses(TestCase::class);

function loTransitions(): ApplicationTransitions
{
    return app(ApplicationTransitions::class);
}

function loApplication(string $status): OriginationApplication
{
    return OriginationApplication::query()->create(['status' => $status]);
}

it('offers staff exactly the moves of the table, with withdrawal from any open status before the hand-off', function () {
    $t = loTransitions();

    expect($t->staffTargets('submitted'))->toEqualCanonicalizing(['document_verification', 'additional_info_required', 'rejected', 'withdrawn'])
        ->and($t->staffTargets('document_verification'))->toEqualCanonicalizing(['additional_info_required', 'under_assessment', 'rejected', 'withdrawn'])
        ->and($t->staffTargets('additional_info_required'))->toEqualCanonicalizing(['document_verification', 'under_assessment', 'rejected', 'withdrawn'])
        ->and($t->staffTargets('under_assessment'))->toEqualCanonicalizing(['additional_info_required', 'rejected', 'withdrawn'])
        ->and($t->staffTargets('pending_approval'))->toBe([])
        ->and($t->staffTargets('draft'))->toBe([])
        ->and($t->staffTargets('converted'))->toBe([]);
});

it('reaches For Approval only through the hand-off, and the loan\'s own outcomes only through the loan', function () {
    $t = loTransitions();

    expect($t->allows('under_assessment', 'pending_approval', ApplicationTransitions::ACTOR_STAFF))->toBeFalse()
        ->and($t->allows('under_assessment', 'pending_approval', ApplicationTransitions::ACTOR_HANDOFF))->toBeTrue()
        ->and($t->allows('pending_approval', 'approved', ApplicationTransitions::ACTOR_STAFF))->toBeFalse()
        ->and($t->allows('pending_approval', 'approved', ApplicationTransitions::ACTOR_LOAN))->toBeTrue()
        ->and($t->allows('approved', 'converted', ApplicationTransitions::ACTOR_LOAN))->toBeTrue()
        ->and($t->allows('approved', 'converted', ApplicationTransitions::ACTOR_STAFF))->toBeFalse()
        ->and($t->allows('pending_approval', 'withdrawn', ApplicationTransitions::ACTOR_LOAN))->toBeTrue()
        ->and($t->allows('draft', 'submitted', ApplicationTransitions::ACTOR_APPLICANT))->toBeTrue()
        ->and($t->allows('incomplete', 'submitted', ApplicationTransitions::ACTOR_APPLICANT))->toBeTrue()
        ->and($t->allows('draft', 'submitted', ApplicationTransitions::ACTOR_STAFF))->toBeFalse()
        ->and($t->allows('draft', 'incomplete', ApplicationTransitions::ACTOR_SYSTEM))->toBeTrue()
        ->and($t->allows('incomplete', 'expired', ApplicationTransitions::ACTOR_SYSTEM))->toBeTrue()
        ->and($t->allows('submitted', 'expired', ApplicationTransitions::ACTOR_SYSTEM))->toBeFalse();
});

it('names the permissions each staff move needs, rejecting with loans:reject too', function () {
    $t = loTransitions();

    expect($t->permissionsFor('rejected'))->toBe(['loan_origination:update', 'loans:reject'])
        ->and($t->permissionsFor('document_verification'))->toBe(['loan_origination:update'])
        ->and($t->permissionsFor('under_assessment'))->toBe(['loan_origination:update']);
});

it('moves an application, writes a history row and stamps the decision time', function () {
    $user = User::query()->where('username', 'super_admin')->firstOrFail();
    $application = loApplication('submitted');

    $moved = loTransitions()->move($application, 'rejected', ApplicationTransitions::ACTOR_STAFF, $user, 'Income could not be verified.');

    expect($moved->status)->toBe('rejected')
        ->and($moved->decided_at)->not->toBeNull();

    $history = OriginationStatusHistory::query()->where('application_id', $application->id)->sole();

    expect($history->from_status)->toBe('submitted')
        ->and($history->to_status)->toBe('rejected')
        ->and($history->actor_type)->toBe('staff')
        ->and($history->user_id)->toBe($user->id)
        ->and($history->note)->toBe('Income could not be verified.');
});

it('stamps submitted_at when the applicant submits', function () {
    $moved = loTransitions()->move(loApplication('draft'), 'submitted', ApplicationTransitions::ACTOR_APPLICANT);

    expect($moved->submitted_at)->not->toBeNull()
        ->and(OriginationStatusHistory::query()->where('application_id', $moved->id)->value('actor_type'))->toBe('applicant');
});

it('refuses a move outside the table with 422 in words and writes nothing', function () {
    $application = loApplication('submitted');

    try {
        loTransitions()->move($application, 'approved', ApplicationTransitions::ACTOR_STAFF);
        $this->fail('The move was allowed.');
    } catch (ValidationException $e) {
        expect($e->errors()['to'][0])->toBe('A Submitted application can\'t be moved to Approved.');
    }

    expect($application->fresh()->status)->toBe('submitted')
        ->and(OriginationStatusHistory::query()->where('application_id', $application->id)->exists())->toBeFalse();
});

it('answers 409 when the application moved since it was read', function () {
    $application = loApplication('submitted');
    DB::table('origination_applications')->where('id', $application->id)->update(['status' => 'document_verification']);

    try {
        loTransitions()->move($application, 'rejected', ApplicationTransitions::ACTOR_STAFF);
        $this->fail('A stale move went through.');
    } catch (HttpResponseException $e) {
        expect($e->getResponse()->getStatusCode())->toBe(409)
            ->and($e->getResponse()->getData(true)['message'])->toBe('This application changed while you were working on it. Refresh to see its current status.');
    }

    expect($application->fresh()->status)->toBe('document_verification');
});

it('reads every status in words', function () {
    expect(ApplicationTransitions::label('pending_approval'))->toBe('For Approval')
        ->and(ApplicationTransitions::label('converted'))->toBe('Converted to Loan')
        ->and(ApplicationTransitions::label('additional_info_required'))->toBe('Additional Information Required');

    foreach (OriginationApplication::STATUSES as $status) {
        expect(ApplicationTransitions::label($status))->not->toContain('_');
    }
});
