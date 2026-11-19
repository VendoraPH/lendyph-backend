<?php

namespace App\Services\Amla\Cases;

use App\Models\AmlaProfile;
use App\Models\AmlaRiskAssessment;
use App\Models\AmlaRiskDowngrade;
use App\Models\Borrower;
use App\Models\User;
use App\Services\Amla\Cdd\CddRecords;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\BranchAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The four-eyes rule for High-risk downgrades (AMLA-B4 review; AMLA-B6):
 * overriding a subject's AML risk down from High clears enhanced due
 * diligence for a non-PEP, so the override waits for a second `amla:cdd`
 * holder who can see the subject, never its requester. Approved, it becomes
 * the requester's override assessment; rejected, nothing changes. One
 * request waits per profile.
 */
final class RiskDowngrades
{
    public const LEVELS = ['low' => 1, 'medium' => 2, 'high' => 3];

    public function __construct(
        private readonly AmlaLog $log,
        private readonly AmlaAccess $access,
        private readonly AmlaInbox $inbox,
        private readonly BranchAccess $branches,
    ) {}

    /** Whether an override to `$level` from the profile's current level is a downgrade from High. */
    public function isDowngradeFromHigh(AmlaProfile $profile, string $level): bool
    {
        $current = $profile->riskAssessments()->latest('id')->value('level');

        return $current === 'high' && (self::LEVELS[$level] ?? 3) < self::LEVELS['high'];
    }

    public function pendingFor(AmlaProfile $profile): bool
    {
        return AmlaRiskDowngrade::query()->where('pending_key', $profile->id)->exists();
    }

    /** Record the request. Runs inside the CDD write's transaction and lock. */
    public function request(User $user, AmlaProfile $profile, string $level, string $reason): AmlaRiskDowngrade
    {
        try {
            $downgrade = AmlaRiskDowngrade::query()->create([
                'profile_id' => $profile->id,
                'from_level' => 'high',
                'to_level' => $level,
                'reason' => trim($reason),
                'status' => AmlaRiskDowngrade::PENDING,
                'pending_key' => $profile->id,
                'requested_by' => $user->id,
            ]);
        } catch (QueryException $duplicate) {
            abort_if(($duplicate->errorInfo[1] ?? null) === 1062, 409, 'A risk downgrade for this subject is already waiting for approval.');

            throw $duplicate;
        }

        $this->log->record('risk_downgrade_requested', $user, 'risk_downgrade', $downgrade->id, ['profile_id' => $profile->id, 'to_level' => $level]);
        $branchId = $profile->borrower_id === null ? null : (int) Borrower::query()->whereKey($profile->borrower_id)->value('branch_id');
        $this->inbox->send($this->inbox->recipients([AmlaAccess::CDD], $branchId, $profile->borrower_id === null, [$user->id]),
            'risk_downgrade_needs_approval', 'risk_downgrade', $downgrade->id, "A High-risk downgrade (request #{$downgrade->id}) is waiting for your approval.");

        return $downgrade;
    }

    /** Pending requests whose subject the user may see. @return Builder<AmlaRiskDowngrade> */
    public function visibleTo(User $user): Builder
    {
        $ids = $this->branches->for($user);

        return AmlaRiskDowngrade::query()->whereHas('profile', fn (Builder $profile) => $ids === null
            ? $profile
            : $profile->whereNull('gcash_non_member_id')->whereIn('borrower_id', Borrower::query()->members()->visibleTo($user)->select('id')));
    }

    public function find(User $user, int $id): AmlaRiskDowngrade
    {
        return $this->visibleTo($user)->whereKey($id)->first() ?? abort(404);
    }

    public function approve(User $user, AmlaRiskDowngrade $downgrade, CddRecords $records): AmlaRiskDowngrade
    {
        $this->refuseRequester($user, $downgrade);

        return DB::transaction(function () use ($user, $downgrade, $records) {
            $locked = $this->lockPending($downgrade);
            $profile = AmlaProfile::query()->with(['borrower', 'walkIn'])->findOrFail($locked->profile_id);
            $subject = $profile->borrower ?? $profile->walkIn;
            $assessment = $records->applyApprovedOverride(User::query()->findOrFail($locked->requested_by), $subject, $locked->to_level, $locked->reason);

            $this->decide($locked, AmlaRiskDowngrade::APPROVED, $user, null, $assessment);
            $this->log->record('risk_downgrade_approved', $user, 'risk_downgrade', $locked->id, ['profile_id' => $locked->profile_id, 'assessment_id' => $assessment->id]);

            return $locked->refresh();
        });
    }

    public function reject(User $user, AmlaRiskDowngrade $downgrade, string $note): AmlaRiskDowngrade
    {
        $this->refuseRequester($user, $downgrade);

        return DB::transaction(function () use ($user, $downgrade, $note) {
            $locked = $this->lockPending($downgrade);
            $this->decide($locked, AmlaRiskDowngrade::REJECTED, $user, trim($note), null);
            $this->log->record('risk_downgrade_rejected', $user, 'risk_downgrade', $locked->id, ['profile_id' => $locked->profile_id]);

            return $locked->refresh();
        });
    }

    private function refuseRequester(User $user, AmlaRiskDowngrade $downgrade): void
    {
        if ($downgrade->requested_by === $user->id) {
            $this->access->refuse($user, 'requester_cannot_decide', 'You asked for this downgrade, so another AMLA CDD holder must decide it.');
        }
    }

    private function lockPending(AmlaRiskDowngrade $downgrade): AmlaRiskDowngrade
    {
        $locked = AmlaRiskDowngrade::query()->lockForUpdate()->findOrFail($downgrade->id);
        abort_unless($locked->status === AmlaRiskDowngrade::PENDING, 409, 'This downgrade has already been decided.');

        return $locked;
    }

    private function decide(AmlaRiskDowngrade $downgrade, string $status, User $user, ?string $note, ?AmlaRiskAssessment $assessment): void
    {
        $downgrade->forceFill([
            'status' => $status,
            'pending_key' => null,
            'decided_by' => $user->id,
            'decided_at' => Carbon::now(),
            'decision_note' => $note,
            'assessment_id' => $assessment?->id,
        ])->save();
    }
}
