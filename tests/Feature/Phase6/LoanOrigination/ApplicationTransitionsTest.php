<?php

use App\Exceptions\OriginationStatusWriteException;
use App\Models\Branch;
use App\Models\OriginationApplication;
use App\Models\OriginationStatusHistory;
use App\Models\Role;
use App\Models\User;
use App\Services\LoanOrigination\ApplicationTransitions;
use Illuminate\Auth\Access\AuthorizationException;
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

/** An application already at $status, set straight in the table as a fixture. */
function loApplication(string $status): OriginationApplication
{
    $application = OriginationApplication::query()->create();
    DB::table('origination_applications')->where('id', $application->id)->update(['status' => $status]);

    return $application->fresh();
}

function loStaff(string ...$permissions): User
{
    $role = Role::query()->create(['name' => 'spec-lo-t:'.uniqid(), 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    return tap(User::factory()->create(['branch_id' => Branch::query()->firstOrFail()->id]), fn (User $u) => $u->assignRole($role));
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
        ->and($t->permissionsFor('pending_approval'))->toBe(['loan_origination:convert', 'loans:create'])
        ->and($t->permissionsFor('document_verification'))->toBe(['loan_origination:update'])
        ->and($t->permissionsFor('under_assessment'))->toBe(['loan_origination:update']);
});

it('moves an application, writes a history row and stamps the decision time', function () {
    $user = User::query()->where('username', 'super_admin')->firstOrFail();
    $application = loApplication('submitted');

    $moved = loTransitions()->move($application, 'rejected', ApplicationTransitions::ACTOR_STAFF, $user, 'Income could not be verified.', $application->version);

    expect($moved->status)->toBe('rejected')
        ->and($moved->decided_at)->not->toBeNull()
        ->and($moved->version)->toBe($application->version + 1);

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
        loTransitions()->move($application, 'approved', ApplicationTransitions::ACTOR_STAFF, null, null, $application->version);
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
        loTransitions()->move($application, 'rejected', ApplicationTransitions::ACTOR_STAFF, loStaff('loan_origination:update', 'loans:reject'), null, $application->version);
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

it('answers 403 in words unless the staff member holds every permission the move needs', function () {
    $application = loApplication('submitted');

    foreach ([loStaff('loan_origination:update'), loStaff('loans:reject'), null] as $user) {
        try {
            loTransitions()->move($application, 'rejected', ApplicationTransitions::ACTOR_STAFF, $user, null, $application->version);
            $this->fail('The move went through without its permissions.');
        } catch (AuthorizationException $e) {
            expect($e->getMessage())->toBe('Moving an application to Rejected needs the permission to update online applications and the permission to reject loans.');
        }
    }

    $moved = loTransitions()->move($application, 'rejected', ApplicationTransitions::ACTOR_STAFF, loStaff('loan_origination:update', 'loans:reject'), null, $application->version);
    expect($moved->status)->toBe('rejected');
});

it('needs a user holding the hand-off permissions for the hand-off', function () {
    $application = loApplication('under_assessment');

    expect(fn () => loTransitions()->move($application, 'pending_approval', ApplicationTransitions::ACTOR_HANDOFF, null, null, $application->version))
        ->toThrow(AuthorizationException::class, 'Moving an application to For Approval needs the permission to hand applications off and the permission to create loans.')
        ->and(fn () => loTransitions()->move($application, 'pending_approval', ApplicationTransitions::ACTOR_HANDOFF, loStaff('loan_origination:convert'), null, $application->version))
        ->toThrow(AuthorizationException::class);

    expect(loTransitions()->move($application, 'pending_approval', ApplicationTransitions::ACTOR_HANDOFF, loStaff('loan_origination:convert', 'loans:create'), null, $application->version)->status)
        ->toBe('pending_approval');
});

it('catches a move made on a version the application has since left, even when its status came back', function () {
    $user = loStaff('loan_origination:update');
    $application = loApplication('document_verification');
    $read = $application->version;

    $other = loTransitions()->move($application, 'additional_info_required', ApplicationTransitions::ACTOR_STAFF, $user, null, $read);
    loTransitions()->move($other, 'document_verification', ApplicationTransitions::ACTOR_STAFF, $user, null, $other->version);

    try {
        loTransitions()->move($application, 'under_assessment', ApplicationTransitions::ACTOR_STAFF, $user, null, $read);
        $this->fail('A move on a stale version went through.');
    } catch (HttpResponseException $e) {
        expect($e->getResponse()->getStatusCode())->toBe(409);
    }

    expect($application->fresh()->status)->toBe('document_verification');
});

it('refuses a status written outside the transition table, by any model path', function () {
    $application = loApplication('submitted');

    expect(fn () => $application->forceFill(['status' => 'approved'])->save())->toThrow(OriginationStatusWriteException::class)
        ->and(fn () => OriginationApplication::query()->whereKey($application->id)->update(['status' => 'approved']))->toThrow(OriginationStatusWriteException::class)
        ->and(fn () => OriginationApplication::query()->create(['status' => 'approved'])->status)->not->toThrow(Throwable::class);

    expect(OriginationApplication::query()->latest('id')->first()->status)->toBe('draft')
        ->and($application->fresh()->status)->toBe('submitted')
        ->and((new OriginationApplication)->isFillable('status'))->toBeFalse();
});
