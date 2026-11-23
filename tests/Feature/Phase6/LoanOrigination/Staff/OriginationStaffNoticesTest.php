<?php

use App\Models\Branch;
use App\Models\OriginationStatusHistory;
use App\Notifications\StaffNotification;
use App\Services\LoanOrigination\ApplicationTransitions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

require_once __DIR__.'/StaffFixtures.php';

/*
 * LO-B3: staff in-app notices for online applications. Staff only, database
 * channel only, after the move commits; a failure is logged and never fails
 * the move. A new submission tells the holders of `loan_origination:update`
 * who can see its branch (and its officer); a decision the loan made tells
 * the assigned officer. Never the person who made the move.
 */
uses(TestCase::class);

beforeEach(function () {
    $this->main = Branch::query()->orderBy('id')->firstOrFail();
    $this->other = Branch::factory()->create(['name' => 'North Branch', 'code' => 'NB']);
});

it('tells the branch\'s processors about a new submission, and nobody elsewhere', function () {
    Notification::fake();
    $processor = lo3Staff($this->main, 'loan_origination:update');
    $viewer = lo3Staff($this->main, 'loan_origination:view');
    $elsewhere = lo3Staff($this->other, 'loan_origination:update');
    $application = lo3Application('draft', ['submitted_at' => null]);

    app(ApplicationTransitions::class)->move($application, 'submitted', ApplicationTransitions::ACTOR_APPLICANT);

    Notification::assertSentTo($processor, StaffNotification::class, fn (StaffNotification $n) => $n->type === 'origination_application_submitted'
        && $n->data['application_id'] === $application->id
        && $n->url === "/loans/origination/applications/{$application->id}"
        && str_contains($n->body, $application->reference_number));
    Notification::assertNotSentTo([$viewer, $elsewhere], StaffNotification::class);
});

it('tells the assigned officer when the loan decides, never the actor', function () {
    Notification::fake();
    $officer = lo3Staff($this->main, 'loan_origination:view');
    $application = lo3Application('pending_approval', ['assigned_officer_id' => $officer->id]);

    app(ApplicationTransitions::class)->move($application, 'approved', ApplicationTransitions::ACTOR_LOAN);

    Notification::assertSentTo($officer, StaffNotification::class, fn (StaffNotification $n) => $n->type === 'origination_application_decided'
        && str_contains($n->body, 'Approved'));

    $mover = lo3Staff($this->main, 'loan_origination:update');
    $other = lo3Application('submitted', ['assigned_officer_id' => $mover->id]);
    $this->actingAs($mover);
    app(ApplicationTransitions::class)->move($other, 'withdrawn', ApplicationTransitions::ACTOR_STAFF, $mover, null, $other->version);
    Notification::assertNotSentTo($mover, StaffNotification::class, fn (StaffNotification $n) => $n->data['application_id'] === $other->id);
});

it('sends nothing when the move rolls back, and never fails a move because a notice failed', function () {
    Notification::fake();
    $processor = lo3Staff($this->main, 'loan_origination:update');
    $application = lo3Application('draft', ['submitted_at' => null]);

    try {
        DB::transaction(function () use ($application) {
            app(ApplicationTransitions::class)->move($application, 'submitted', ApplicationTransitions::ACTOR_APPLICANT);
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    Notification::assertNothingSent();

    Notification::shouldReceive('send')->andThrow(new RuntimeException('bell broken'));
    Log::spy();
    $fresh = lo3Application('draft', ['submitted_at' => null]);
    app(ApplicationTransitions::class)->move($fresh, 'submitted', ApplicationTransitions::ACTOR_APPLICANT);

    expect($fresh->fresh()->status)->toBe('submitted')
        ->and(OriginationStatusHistory::query()->where('application_id', $fresh->id)->count())->toBe(1);
    Log::shouldHaveReceived('error')->once();
    expect($processor)->not->toBeNull();
});
