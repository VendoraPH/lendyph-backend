<?php

namespace App\Services\LoanOrigination;

use App\Models\OriginationApplication;
use App\Models\OriginationStatusHistory;
use App\Models\User;
use App\Services\LoanWriteTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The server's transition table for online applications (origination audit
 * §3.1 and §5, decisions 1 and 18). Every status change goes through move():
 * it locks the row, checks the move against the table for its actor, writes
 * the history row and stamps the dates.
 *
 * Who moves what:
 * - the applicant: their draft to submitted (and an incomplete draft back to
 *   draft when they save again);
 * - the system: a draft idle for 24 hours to incomplete, an unsubmitted one
 *   to expired;
 * - staff: the review steps, and rejecting (which also needs `loans:reject`)
 *   or withdrawing any open application before the hand-off;
 * - the hand-off ("Submit for approval"): under assessment to For Approval,
 *   once it has submitted the draft loan through the loan code;
 * - the loan: from then on the application follows its loan. Approved,
 *   rejected, released (converted), voided before release (withdrawn), sent
 *   back to the chain (For Approval again) or back to draft (under
 *   assessment, so it can be handed off again).
 *
 * #615's "Convert" is gone: no actor moves approved to converted except the
 * loan's release.
 */
final class ApplicationTransitions
{
    public const ACTOR_STAFF = 'staff';

    public const ACTOR_APPLICANT = 'applicant';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_HANDOFF = 'handoff';

    public const ACTOR_LOAN = 'loan';

    public const ACTORS = [self::ACTOR_STAFF, self::ACTOR_APPLICANT, self::ACTOR_SYSTEM, self::ACTOR_HANDOFF, self::ACTOR_LOAN];

    public const STALE = 'This application changed while you were working on it. Refresh to see its current status.';

    public const LABELS = [
        'draft' => 'Draft',
        'incomplete' => 'Incomplete',
        'submitted' => 'Submitted',
        'document_verification' => 'Under Document Verification',
        'additional_info_required' => 'Additional Information Required',
        'under_assessment' => 'Under Assessment',
        'pending_approval' => 'For Approval',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'withdrawn' => 'Withdrawn',
        'converted' => 'Converted to Loan',
        'expired' => 'Expired',
    ];

    /**
     * from => [to => actors allowed].
     *
     * @var array<string, array<string, list<string>>>
     */
    private const TABLE = [
        'draft' => [
            'submitted' => [self::ACTOR_APPLICANT],
            'incomplete' => [self::ACTOR_SYSTEM],
            'expired' => [self::ACTOR_SYSTEM],
        ],
        'incomplete' => [
            'draft' => [self::ACTOR_APPLICANT],
            'submitted' => [self::ACTOR_APPLICANT],
            'expired' => [self::ACTOR_SYSTEM],
        ],
        'submitted' => [
            'document_verification' => [self::ACTOR_STAFF],
            'additional_info_required' => [self::ACTOR_STAFF],
            'rejected' => [self::ACTOR_STAFF],
            'withdrawn' => [self::ACTOR_STAFF],
        ],
        'document_verification' => [
            'additional_info_required' => [self::ACTOR_STAFF],
            'under_assessment' => [self::ACTOR_STAFF],
            'rejected' => [self::ACTOR_STAFF],
            'withdrawn' => [self::ACTOR_STAFF],
        ],
        'additional_info_required' => [
            'document_verification' => [self::ACTOR_STAFF],
            'under_assessment' => [self::ACTOR_STAFF],
            'rejected' => [self::ACTOR_STAFF],
            'withdrawn' => [self::ACTOR_STAFF],
        ],
        'under_assessment' => [
            'additional_info_required' => [self::ACTOR_STAFF],
            'rejected' => [self::ACTOR_STAFF],
            'withdrawn' => [self::ACTOR_STAFF],
            'pending_approval' => [self::ACTOR_HANDOFF],
        ],
        'pending_approval' => [
            'approved' => [self::ACTOR_LOAN],
            'rejected' => [self::ACTOR_LOAN],
            'withdrawn' => [self::ACTOR_LOAN],
            'under_assessment' => [self::ACTOR_LOAN],
            'converted' => [self::ACTOR_LOAN],
        ],
        'approved' => [
            'converted' => [self::ACTOR_LOAN],
            'withdrawn' => [self::ACTOR_LOAN],
            'rejected' => [self::ACTOR_LOAN],
            'pending_approval' => [self::ACTOR_LOAN],
        ],
    ];

    /** Statuses that record a decision time. */
    private const DECISIONS = ['approved', 'rejected', 'withdrawn', 'converted', 'expired'];

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public function allows(string $from, string $to, string $actor): bool
    {
        return in_array($actor, self::TABLE[$from][$to] ?? [], true);
    }

    /**
     * The moves staff may make by hand from $from, for the status buttons.
     *
     * @return list<string>
     */
    public function staffTargets(string $from): array
    {
        return array_keys(array_filter(self::TABLE[$from] ?? [], fn (array $actors) => in_array(self::ACTOR_STAFF, $actors, true)));
    }

    /**
     * The permissions a staff move to $to needs: `loan_origination:update`,
     * and rejecting also `loans:reject` ("Reject keeps its own loans:reject").
     *
     * @return list<string>
     */
    public function permissionsFor(string $to): array
    {
        return $to === 'rejected' ? ['loan_origination:update', 'loans:reject'] : ['loan_origination:update'];
    }

    /**
     * Move $application to $to as $actor: lock, re-check, write history.
     *
     * @throws ValidationException 422 when the table doesn't allow the move
     */
    public function move(OriginationApplication $application, string $to, string $actor, ?User $user = null, ?string $note = null): OriginationApplication
    {
        return DB::transaction(function () use ($application, $to, $actor, $user, $note): OriginationApplication {
            /** @var OriginationApplication $locked */
            $locked = OriginationApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== $application->status) {
                throw LoanWriteTransaction::conflict(self::STALE);
            }

            $from = $locked->status;

            if (! in_array($actor, self::ACTORS, true) || ! $this->allows($from, $to, $actor)) {
                throw ValidationException::withMessages([
                    'to' => sprintf('A%s %s application can\'t be moved to %s.', $this->article($from), self::label($from), self::label($to)),
                ]);
            }

            $now = now();
            $locked->status = $to;

            if ($to === 'submitted' && $locked->submitted_at === null) {
                $locked->submitted_at = $now;
            }

            if (in_array($to, self::DECISIONS, true)) {
                $locked->decided_at = $now;
            }

            $locked->save();

            OriginationStatusHistory::query()->create([
                'application_id' => $locked->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_type' => $actor,
                'user_id' => $actor === self::ACTOR_STAFF || $actor === self::ACTOR_HANDOFF ? $user?->id : null,
                'note' => $note,
            ]);

            return $locked;
        });
    }

    /** "A Submitted application", "An Incomplete application". */
    private function article(string $status): string
    {
        return in_array(strtolower(self::label($status)[0]), ['a', 'e', 'i', 'o', 'u'], true) ? 'n' : '';
    }
}
