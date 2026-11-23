<?php

namespace App\Services\LoanOrigination\Staff;

use App\Models\Borrower;
use App\Models\OriginationApplicantMessage;
use App\Models\OriginationApplication;
use App\Models\OriginationDocument;
use App\Models\OriginationNote;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\BranchAccess;
use App\Services\LoanOrigination\ApplicationTransitions;
use App\Services\LoanWriteTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff work on an online application (LO-B3): moves, notes, assignment,
 * information requests, resume reminders and linking a member.
 *
 * - Every status change goes through LO-B1's ApplicationTransitions::move(),
 *   with the version the client read (409 when it changed meanwhile).
 *   Writes that don't move the status (assignment, linking a member, a
 *   follow-up information request) re-read the row under a lock and check
 *   the same version, so they can't overwrite a change either.
 * - Messages to the applicant are logged, never sent (ApplicantMessages).
 * - Audit rows hold ids and statuses only, never what anyone typed.
 */
final class StaffProcessing
{
    /** A follow-up information request may also come while one is open. */
    private const INFORMATION_FROM = ['submitted', 'document_verification', 'under_assessment', 'additional_info_required'];

    /** Document statuses an information request asks for again. */
    private const RESETTABLE = ['uploaded', 'pending_verification', 'verified', 'rejected'];

    public const OFFICER_REFUSED = 'That staff member can\'t be assigned to this application.';

    public const MEMBER_REFUSED = 'That member can\'t be linked to this application.';

    public const FORMER_MEMBER = 'A withdrawn or deceased member can\'t apply for a new loan, so they can\'t be linked.';

    public const HANDED_OFF = 'This application has been handed off or closed, so its member can\'t change.';

    public const REMINDER_RECENT = 'A reminder was already logged for this application in the last 24 hours.';

    public const REMINDER_NOT_DRAFT = 'Only an application the applicant hasn\'t submitted yet can be reminded.';

    public const REMINDER_NO_CONTACT = 'This application has no mobile number or email to remind.';

    public function __construct(
        private readonly ApplicationTransitions $transitions,
        private readonly ApplicantMessages $messages,
        private readonly BranchAccess $branches,
        private readonly OriginationStaffNotices $notices,
    ) {}

    /**
     * A staff move, with an optional message for the applicant. A rejection
     * or withdrawal's message is the decision notice (`applicant_message`,
     * which tracking shows); the internal note stays in the history.
     */
    public function transition(OriginationApplication $application, string $to, User $user, int $version, ?string $note, ?string $applicantMessage): ?OriginationApplicantMessage
    {
        return DB::transaction(function () use ($application, $to, $user, $version, $note, $applicantMessage): ?OriginationApplicantMessage {
            $moved = $this->transitions->move($application, $to, ApplicationTransitions::ACTOR_STAFF, $user, $note, $version);

            if (blank($applicantMessage)) {
                return null;
            }

            $decision = in_array($to, ['rejected', 'withdrawn'], true);

            if ($decision) {
                $moved->forceFill(['applicant_message' => $applicantMessage])->save();
            }

            return $this->messages->log($moved, $decision ? 'decision' : 'status_change', (string) $applicantMessage, $user);
        });
    }

    public function addNote(OriginationApplication $application, User $user, string $body): OriginationNote
    {
        return DB::transaction(function () use ($application, $user, $body): OriginationNote {
            $note = OriginationNote::query()->create(['application_id' => $application->id, 'user_id' => $user->id, 'body' => $body]);

            AuditLogService::log('origination_note_added', $application, null, ['note_id' => $note->id], 'Added an internal note to an online application.');

            return $note;
        });
    }

    /**
     * Assign (or clear) the officer: an active user holding
     * `loan_origination:update` who can see the application's branch. A
     * refused pick answers one fixed message that names no one.
     */
    public function assign(OriginationApplication $application, ?int $officerId, User $user, int $version): OriginationApplication
    {
        $officer = $officerId === null ? null : User::query()->find($officerId);

        if ($officerId !== null && ! $this->canBeAssigned($officer, $application)) {
            throw ValidationException::withMessages(['officer_id' => self::OFFICER_REFUSED]);
        }

        return DB::transaction(function () use ($application, $officer, $user, $version): OriginationApplication {
            $locked = $this->lockAt($application, $version);
            $old = $locked->assigned_officer_id;

            if ($old === $officer?->id) {
                return $locked;
            }

            $locked->forceFill(['assigned_officer_id' => $officer?->id])->save();

            AuditLogService::log('origination_assigned', $locked, ['assigned_officer_id' => $old], ['assigned_officer_id' => $officer?->id], 'Changed the officer of an online application.');

            if ($officer !== null) {
                $this->notices->assigned($locked, $officer, $user);
            }

            return $locked;
        });
    }

    public function canBeAssigned(?User $officer, OriginationApplication $application): bool
    {
        return $officer !== null
            && $officer->status === 'active'
            && $officer->can('loan_origination:update')
            && $this->branches->canSee($officer, $application->branch_id === null ? null : (int) $application->branch_id);
    }

    /**
     * Ask the applicant for more: moves the application to Additional
     * Information Required (or keeps it there for a follow-up), asks again
     * for the named documents (clearing any verification, one audit row
     * each), and logs the message. A lock collision answers 409, never 500.
     *
     * @param  list<string>  $documentTypes
     */
    public function requestInformation(OriginationApplication $application, User $user, int $version, string $message, array $documentTypes): OriginationApplicantMessage
    {
        if (! in_array($application->status, self::INFORMATION_FROM, true)) {
            throw ValidationException::withMessages([
                'to' => sprintf('Information can\'t be requested on a%s %s application.', $this->article($application->status), ApplicationTransitions::label($application->status)),
            ]);
        }

        return LoanWriteTransaction::run(conflictMessage: ApplicationTransitions::STALE, callback: function () use ($application, $user, $version, $message, $documentTypes): OriginationApplicantMessage {
            // The documents first, then the application: the order uploads
            // and document decisions take them in, so they never deadlock.
            $documents = $documentTypes === [] ? collect() : OriginationDocument::query()
                ->where('application_id', $application->id)
                ->whereIn('type', $documentTypes)
                ->whereIn('status', self::RESETTABLE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $current = $application->status === 'additional_info_required'
                ? $this->lockAt($application, $version)
                : $this->transitions->move($application, 'additional_info_required', ApplicationTransitions::ACTOR_STAFF, $user, null, $version);

            foreach ($documents as $document) {
                $from = $document->status;
                $document->forceFill(['status' => 'replacement_requested', 'verified_by' => null, 'verified_at' => null])->save();

                AuditLogService::log('origination_document_replacement_requested', $document, ['status' => $from], ['status' => 'replacement_requested', 'application_id' => $current->id], 'Asked an online applicant for a new copy of a document.');
            }

            if ($application->status === 'additional_info_required') {
                OriginationApplication::query()->whereKey($current->id)->update(['updated_at' => now()]);
            }

            AuditLogService::log('origination_information_requested', $current, null, ['document_types' => $documentTypes], 'Asked an online applicant for more information.');

            return $this->messages->log($current, 'information_request', $message, $user, $documentTypes);
        });
    }

    /**
     * A reminder to finish an unsubmitted application, logged (never sent),
     * at most once in 24 hours.
     *
     * @return array<string, mixed>
     */
    public function remind(OriginationApplication $application, User $user): array
    {
        return DB::transaction(function () use ($application, $user): array {
            /** @var OriginationApplication $locked */
            $locked = OriginationApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isUnsubmitted()) {
                throw ValidationException::withMessages(['application' => self::REMINDER_NOT_DRAFT]);
            }

            if ($this->messages->channelFor($locked) === null) {
                throw ValidationException::withMessages(['application' => self::REMINDER_NO_CONTACT]);
            }

            $recent = OriginationApplicantMessage::query()
                ->where('application_id', $locked->id)
                ->where('kind', 'resume_reminder')
                ->where('created_at', '>=', now()->subDay())
                ->exists();

            if ($recent) {
                throw ValidationException::withMessages(['application' => self::REMINDER_RECENT]);
            }

            $message = $this->messages->log(
                $locked,
                'resume_reminder',
                'Your loan application isn\'t finished yet. Open your application link and sign in with a new code to continue where you left off.',
                $user,
            );

            AuditLogService::log('origination_reminder_logged', $locked, null, ['message_id' => $message->id], 'Logged a reminder for an unfinished online application (not sent).');

            return [
                ...ApplicantMessages::present($message),
                'logged_at' => $message->created_at?->toIso8601String(),
            ];
        });
    }

    /**
     * Link the application to an existing member the caller can see, or
     * clear the link, until the hand-off. The member's record is never
     * changed. A former member is refused; any other member the caller can't
     * reach answers one fixed message.
     */
    public function linkMember(OriginationApplication $application, ?int $borrowerId, User $user, int $version): OriginationApplication
    {
        $member = $borrowerId === null ? null : Borrower::query()->visibleTo($user)->find($borrowerId);

        if ($borrowerId !== null && ($member === null || $member->status === 'rejected')) {
            throw ValidationException::withMessages(['borrower_id' => self::MEMBER_REFUSED]);
        }

        if ($member?->isFormerMember()) {
            throw ValidationException::withMessages(['borrower_id' => self::FORMER_MEMBER]);
        }

        return DB::transaction(function () use ($application, $member, $version): OriginationApplication {
            $locked = $this->lockAt($application, $version);

            if ($locked->loan_id !== null || $locked->isClosed() || in_array($locked->status, ['pending_approval', 'approved'], true)) {
                throw ValidationException::withMessages(['borrower_id' => self::HANDED_OFF]);
            }

            $old = $locked->borrower_id;

            if ($old === $member?->id) {
                return $locked;
            }

            $locked->forceFill(['borrower_id' => $member?->id])->save();

            AuditLogService::log(
                $member === null ? 'origination_member_unlinked' : 'origination_member_linked',
                $locked,
                ['borrower_id' => $old],
                ['borrower_id' => $member?->id],
                $member === null ? 'Cleared the member linked to an online application.' : 'Linked an online application to an existing member.',
            );

            return $locked;
        });
    }

    /** The application re-read under a lock, at the version the client read (409 otherwise). */
    private function lockAt(OriginationApplication $application, int $version): OriginationApplication
    {
        /** @var OriginationApplication $locked */
        $locked = OriginationApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

        if ($locked->version !== $version) {
            throw LoanWriteTransaction::conflict(ApplicationTransitions::STALE);
        }

        return $locked;
    }

    private function article(string $status): string
    {
        return in_array(strtolower(ApplicationTransitions::label($status)[0]), ['a', 'e', 'i', 'o', 'u'], true) ? 'n' : '';
    }
}
