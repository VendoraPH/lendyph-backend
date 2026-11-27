<?php

namespace App\Services\LoanOrigination;

use App\Models\OriginationApplication;
use App\Models\OriginationStatusHistory;
use App\Models\User;
use App\Services\LoanWriteTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

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

    private const PERMISSION_WORDS = [
        'loan_origination:update' => 'the permission to update online applications',
        'loans:reject' => 'the permission to reject loans',
        'loan_origination:convert' => 'the permission to hand applications off',
        'loans:create' => 'the permission to create loans',
    ];

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
     * The permissions a staff or hand-off move to $to needs:
     * `loan_origination:update`; rejecting also `loans:reject` ("Reject keeps
     * its own loans:reject"); the hand-off to For Approval
     * `loan_origination:convert` and `loans:create` (LO-B4 also asks for
     * `borrowers:create` when it creates the member).
     *
     * @return list<string>
     */
    public function permissionsFor(string $to): array
    {
        return match ($to) {
            'rejected' => ['loan_origination:update', 'loans:reject'],
            'pending_approval' => ['loan_origination:convert', 'loans:create'],
            default => ['loan_origination:update'],
        };
    }

    /**
     * Move $application to $to as $actor: lock, re-check, write history.
     *
     * A staff or hand-off move needs a user holding every permission in
     * permissionsFor($to) (403 in words), and the version the client read
     * ($expectedVersion): a move on any other version answers 409, so a
     * change made meanwhile is never overwritten, even when the status came
     * back to what the client saw. Applicant, system and loan moves check the
     * version the application was read at.
     *
     * @throws ValidationException 422 when the table doesn't allow the move
     * @throws AuthorizationException 403 when the user lacks a permission
     */
    public function move(
        OriginationApplication $application,
        string $to,
        string $actor,
        ?User $user = null,
        ?string $note = null,
        ?int $expectedVersion = null,
    ): OriginationApplication {
        $byStaff = in_array($actor, [self::ACTOR_STAFF, self::ACTOR_HANDOFF], true);

        if ($byStaff && $expectedVersion === null) {
            throw new InvalidArgumentException('A staff or hand-off move needs the application version the client read.');
        }

        return DB::transaction(function () use ($application, $to, $actor, $user, $note, $expectedVersion, $byStaff): OriginationApplication {
            /** @var OriginationApplication $locked */
            $locked = OriginationApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== $application->status || $locked->version !== ($expectedVersion ?? $application->version)) {
                throw LoanWriteTransaction::conflict(self::STALE);
            }

            $from = $locked->status;

            if (! in_array($actor, self::ACTORS, true) || ! $this->allows($from, $to, $actor)) {
                throw ValidationException::withMessages([
                    'to' => sprintf('A%s %s application can\'t be moved to %s.', $this->article($from), self::label($from), self::label($to)),
                ]);
            }

            if ($byStaff) {
                $this->authorize($user, $to);
            }

            $now = now();

            OriginationApplication::withinTransition(function () use ($locked, $to, $now): void {
                $locked->status = $to;

                if ($to === 'submitted' && $locked->submitted_at === null) {
                    $locked->submitted_at = $now;
                }

                if (in_array($to, self::DECISIONS, true)) {
                    $locked->decided_at = $now;
                }

                $locked->save();
            });

            OriginationStatusHistory::query()->create([
                'application_id' => $locked->id,
                'from_status' => $from,
                'to_status' => $to,
                'actor_type' => $actor,
                'user_id' => $byStaff ? $user?->id : null,
                'note' => $note,
            ]);

            return $locked;
        });
    }

    /** @throws AuthorizationException */
    private function authorize(?User $user, string $to): void
    {
        $needed = $this->permissionsFor($to);

        if ($user !== null && collect($needed)->every(fn (string $permission) => $user->can($permission))) {
            return;
        }

        $words = array_map(fn (string $permission) => self::PERMISSION_WORDS[$permission] ?? 'another permission', $needed);

        throw new AuthorizationException(sprintf('Moving an application to %s needs %s.', self::label($to), implode(' and ', $words)));
    }

    /** "A Submitted application", "An Incomplete application". */
    private function article(string $status): string
    {
        return in_array(strtolower(self::label($status)[0]), ['a', 'e', 'i', 'o', 'u'], true) ? 'n' : '';
    }
}
