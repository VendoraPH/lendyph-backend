<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\OriginationApplicantMessage;
use App\Models\OriginationApplication;
use App\Models\OriginationNote;
use App\Models\OriginationStatusHistory;
use App\Notifications\StaffNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

require_once __DIR__.'/StaffFixtures.php';

/*
 * LO-B3: staff moves, notes, assignment, information requests and reminders.
 * Every move goes through LO-B1's ApplicationTransitions (the table, the
 * version the client read, `loans:reject` to reject). Messages to the
 * applicant are recorded as "Logged, not sent": nothing reaches an SMS or
 * email provider (testing.md: never contact real people).
 */
uses(TestCase::class);

beforeEach(function () {
    Storage::fake('private');
    $this->main = Branch::query()->orderBy('id')->firstOrFail();
    $this->other = Branch::factory()->create(['name' => 'North Branch', 'code' => 'NB']);
});

it('moves an application through the table and writes its history', function () {
    $application = lo3Application('submitted');
    $user = lo3Staff($this->main, 'loan_origination:update');

    $this->actingAs($user)->postJson("/api/loan-origination/applications/{$application->id}/transition", [
        'to' => 'document_verification', 'note' => 'Checking the ID.', 'version' => $application->version,
    ])->assertOk()->assertJsonPath('data.status', 'document_verification');

    $history = OriginationStatusHistory::query()->where('application_id', $application->id)->latest('id')->first();
    expect($history->to_status)->toBe('document_verification')
        ->and($history->user_id)->toBe($user->id)
        ->and($history->note)->toBe('Checking the ID.');
});

it('refuses a move outside the table (422), on a stale version (409), and a reject without loans:reject (403)', function () {
    $application = lo3Application('submitted');
    $user = lo3Staff($this->main, 'loan_origination:update');

    $this->actingAs($user)->postJson("/api/loan-origination/applications/{$application->id}/transition", ['to' => 'pending_approval', 'version' => $application->version])
        ->assertUnprocessable();
    $this->actingAs($user)->postJson("/api/loan-origination/applications/{$application->id}/transition", ['to' => 'document_verification', 'version' => $application->version + 5])
        ->assertConflict();
    $this->actingAs($user)->postJson("/api/loan-origination/applications/{$application->id}/transition", ['to' => 'rejected', 'version' => $application->version])
        ->assertForbidden();

    expect($application->fresh()->status)->toBe('submitted');
});

it('rejects with an applicant message that is logged, never sent, apart from the internal note', function () {
    $application = lo3Application('under_assessment');
    $user = lo3Staff($this->main, 'loan_origination:update', 'loans:reject');

    $response = $this->actingAs($user)->postJson("/api/loan-origination/applications/{$application->id}/transition", [
        'to' => 'rejected', 'note' => 'Income too low for the amount.', 'applicant_message' => 'We can\'t approve this application now.', 'version' => $application->version,
    ])->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.applicant_message.delivery', 'logged')
        ->assertJsonPath('data.applicant_message.delivery_label', 'Logged, not sent')
        ->assertJsonPath('data.applicant_message.delivered', false);

    $message = OriginationApplicantMessage::query()->where('application_id', $application->id)->sole();
    expect($message->kind)->toBe('decision')
        ->and($message->body)->toBe('We can\'t approve this application now.')
        ->and($message->channel)->toBe('sms')
        ->and($application->fresh()->applicant_message)->toBe('We can\'t approve this application now.')
        ->and($response->json('data.history.0.note'))->toBe('Income too low for the amount.');
});

it('needs loan_origination:update for every write', function () {
    $application = lo3Application('submitted');
    $viewer = lo3Staff($this->main, 'loan_origination:view');
    $base = "/api/loan-origination/applications/{$application->id}";

    $this->actingAs($viewer)->postJson("{$base}/transition", ['to' => 'document_verification', 'version' => $application->version])->assertForbidden();
    $this->actingAs($viewer)->postJson("{$base}/notes", ['body' => 'x'])->assertForbidden();
    $this->actingAs($viewer)->postJson("{$base}/assign", ['officer_id' => null, 'version' => $application->version])->assertForbidden();
    $this->actingAs($viewer)->postJson("{$base}/request-information", ['message' => 'x', 'version' => $application->version])->assertForbidden();
    $this->actingAs($viewer)->postJson("{$base}/remind")->assertForbidden();
});

it('adds a permanent internal note, audited by ids only', function () {
    $application = lo3Application('submitted');
    $user = lo3Staff($this->main, 'loan_origination:update');
    $before = AuditLog::query()->max('id') ?? 0;

    $this->actingAs($user)->postJson("/api/loan-origination/applications/{$application->id}/notes", ['body' => 'Spoke to the employer.'])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Spoke to the employer.')
        ->assertJsonPath('data.author_name', $user->full_name);

    expect(OriginationNote::query()->where('application_id', $application->id)->count())->toBe(1);
    $audit = AuditLog::query()->where('id', '>', $before)->where('action', 'origination_note_added')->sole();
    expect(json_encode($audit->new_values))->not->toContain('employer');

    $this->actingAs($user)->postJson("/api/loan-origination/applications/{$application->id}/notes", ['body' => ''])->assertUnprocessable();
});

it('assigns an officer who holds loan_origination:update and sees the branch, and tells them', function () {
    Notification::fake();
    $application = lo3Application('submitted');
    $user = lo3Staff($this->main, 'loan_origination:update');
    $officer = lo3Staff($this->main, 'loan_origination:update');

    $this->actingAs($user)->postJson("/api/loan-origination/applications/{$application->id}/assign", ['officer_id' => $officer->id, 'version' => $application->version])
        ->assertOk()
        ->assertJsonPath('data.assigned_officer_id', $officer->id)
        ->assertJsonPath('data.assigned_officer_name', $officer->full_name);

    Notification::assertSentTo($officer, StaffNotification::class, fn (StaffNotification $n) => $n->type === 'origination_application_assigned'
        && $n->data['application_id'] === $application->id);
    Notification::assertNotSentTo($user, StaffNotification::class);
    expect(AuditLog::query()->where('action', 'origination_assigned')->where('auditable_id', $application->id)->exists())->toBeTrue();
});

it('refuses an officer without the permission or the branch with one fixed message that names nobody', function () {
    $application = lo3Application('submitted');
    $user = lo3Staff($this->main, 'loan_origination:update');
    $noPermission = lo3Staff($this->main, 'loans:view');
    $otherBranch = lo3Staff($this->other, 'loan_origination:update');

    foreach ([$noPermission, $otherBranch] as $pick) {
        $response = $this->actingAs($user)->postJson("/api/loan-origination/applications/{$application->id}/assign", ['officer_id' => $pick->id, 'version' => $application->version])
            ->assertUnprocessable();
        expect($response->json('errors.officer_id.0'))->toBe('That staff member can\'t be assigned to this application.');
    }

    $this->actingAs($user)->postJson("/api/loan-origination/applications/{$application->id}/assign", ['officer_id' => null, 'version' => $application->version + 3])
        ->assertConflict();
});

it('requests information: moves to Additional Information Required, asks for the files again and logs the message', function () {
    $application = lo3Application('document_verification');
    $document = lo3Document($application, 'proof_of_income', 'pending_verification');
    $user = lo3Staff($this->main, 'loan_origination:update');

    $this->actingAs($user)->postJson("/api/loan-origination/applications/{$application->id}/request-information", [
        'message' => 'Please send your latest payslip.', 'document_types' => ['proof_of_income'], 'version' => $application->version,
    ])->assertOk()
        ->assertJsonPath('data.status', 'additional_info_required')
        ->assertJsonPath('data.applicant_message.kind', 'information_request')
        ->assertJsonPath('data.applicant_message.delivered', false);

    expect($document->fresh()->status)->toBe('replacement_requested');
    $message = OriginationApplicantMessage::query()->where('application_id', $application->id)->sole();
    expect($message->document_types)->toBe(['proof_of_income'])->and($message->delivery)->toBe('logged');
});

it('refuses an information request for an unknown document type or from a status the table doesn\'t allow', function () {
    $application = lo3Application('submitted');
    $user = lo3Staff($this->main, 'loan_origination:update');

    $this->actingAs($user)->postJson("/api/loan-origination/applications/{$application->id}/request-information", [
        'message' => 'Send it.', 'document_types' => ['passport_selfie'], 'version' => $application->version,
    ])->assertUnprocessable()->assertJsonValidationErrors('document_types.0');

    $closed = lo3Application('withdrawn');
    $this->actingAs($user)->postJson("/api/loan-origination/applications/{$closed->id}/request-information", [
        'message' => 'Send it.', 'version' => $closed->version,
    ])->assertUnprocessable();

    expect(OriginationApplicantMessage::query()->count())->toBe(0);
});

it('logs a resume reminder for an unfinished draft, at most once a day', function () {
    $application = lo3Application('incomplete');
    $user = lo3Staff($this->main, 'loan_origination:update');

    $this->actingAs($user)->postJson("/api/loan-origination/applications/{$application->id}/remind")
        ->assertOk()
        ->assertJsonPath('data.channel', 'sms')
        ->assertJsonPath('data.sent_at', null)
        ->assertJsonPath('data.delivered', false)
        ->assertJsonPath('data.delivery_label', 'Logged, not sent');

    $this->actingAs($user)->postJson("/api/loan-origination/applications/{$application->id}/remind")->assertUnprocessable();

    $submitted = lo3Application('submitted');
    $this->actingAs($user)->postJson("/api/loan-origination/applications/{$submitted->id}/remind")->assertUnprocessable();

    expect(OriginationApplicantMessage::query()->where('kind', 'resume_reminder')->count())->toBe(1);
});

it('keeps applicant messages append-only', function () {
    $application = lo3Application('submitted');
    $message = OriginationApplicantMessage::query()->create([
        'application_id' => $application->id, 'kind' => 'status_change', 'channel' => 'sms', 'body' => 'x', 'delivery' => 'logged',
    ]);

    expect(fn () => $message->update(['body' => 'y']))->toThrow(Exception::class)
        ->and(fn () => $message->delete())->toThrow(Exception::class)
        ->and(OriginationApplication::query()->whereKey($application->id)->exists())->toBeTrue();
});
