<?php

namespace App\Services\LoanOrigination\Staff;

use App\Models\OriginationApplication;
use App\Models\OriginationStatusHistory;
use App\Models\User;
use App\Notifications\StaffNotification;
use App\Services\BranchAccess;
use App\Services\LoanOrigination\ApplicationTransitions;
use App\Services\Savings\Notices\StaffRecipients;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Staff in-app notices for online applications (LO-B3). Staff only, the
 * database channel only (StaffNotification), like every other notice; an
 * applicant is never told anything here. Its own types, so Notifications.php
 * is untouched:
 *
 * - `origination_application_submitted`: an applicant submitted. Told: the
 *   active holders of `loan_origination:update` who can see its branch, and
 *   its assigned officer.
 * - `origination_application_assigned`: an application was assigned to you.
 * - `origination_application_decided`: the loan an application became was
 *   approved, rejected, released or voided (the application followed it).
 *   Told: the assigned officer.
 *
 * Every recipient is active, holds `loan_origination:view` or `update`, can
 * see the application's branch, and is never the person who acted. Sent
 * after the surrounding write commits (a rolled-back move tells no one); a
 * failure is logged and never fails the move.
 */
final class OriginationStaffNotices
{
    public const SUBMITTED = 'origination_application_submitted';

    public const ASSIGNED = 'origination_application_assigned';

    public const DECIDED = 'origination_application_decided';

    /** The loan outcomes an application follows that its officer hears about. */
    private const LOAN_OUTCOMES = ['approved', 'rejected', 'converted', 'withdrawn'];

    public function __construct(
        private readonly StaffRecipients $staff,
        private readonly BranchAccess $branches,
    ) {}

    /** Called for every new status history row (OriginationStatusHistoryObserver). */
    public function afterMove(OriginationStatusHistory $row): void
    {
        $historyId = (int) $row->getKey();

        if ($row->to_status === 'submitted') {
            $this->later($historyId, fn () => $this->sendSubmitted($historyId));
        } elseif ($row->actor_type === ApplicationTransitions::ACTOR_LOAN && in_array($row->to_status, self::LOAN_OUTCOMES, true)) {
            $this->later($historyId, fn () => $this->sendDecided($historyId));
        }
    }

    public function assigned(OriginationApplication $application, User $officer, ?User $actor): void
    {
        if ($actor !== null && (int) $actor->getKey() === (int) $officer->getKey()) {
            return;
        }

        $applicationId = (int) $application->getKey();
        $officerId = (int) $officer->getKey();
        $actorId = $actor === null ? null : (int) $actor->getKey();

        $this->later(null, function () use ($applicationId, $officerId, $actorId): void {
            $application = OriginationApplication::query()->find($applicationId);

            if ($application === null) {
                return;
            }

            $recipients = $this->eligible($application, User::query()->with(['roles', 'permissions'])->whereKey($officerId)->get()->toBase(), $actorId);

            $this->send($recipients, new StaffNotification(
                self::ASSIGNED,
                'Online application assigned to you',
                sprintf('%s from %s is now yours to process.', $this->label($application), $this->applicant($application)),
                $this->url($application),
                ['application_id' => $application->id],
            ));
        });
    }

    private function sendSubmitted(int $historyId): void
    {
        $row = OriginationStatusHistory::query()->with('application')->find($historyId);
        $application = $row?->application;

        if ($application === null || $application->status !== 'submitted') {
            return;
        }

        $branchId = $application->branch_id === null ? null : (int) $application->branch_id;
        $candidates = $this->staff->holding('loan_origination:update', $branchId);

        if ($application->assigned_officer_id !== null) {
            $candidates = $candidates->merge(User::query()->whereKey($application->assigned_officer_id)->get());
        }

        $this->send($this->eligible($application, $candidates, $row->user_id), new StaffNotification(
            self::SUBMITTED,
            'New online application',
            sprintf(
                '%s from %s was submitted%s.',
                $this->label($application),
                $this->applicant($application),
                $application->requested_amount === null ? '' : ' for ₱'.number_format((float) $application->requested_amount, 2),
            ),
            $this->url($application),
            ['application_id' => $application->id],
        ));
    }

    private function sendDecided(int $historyId): void
    {
        $row = OriginationStatusHistory::query()->with('application')->find($historyId);
        $application = $row?->application;

        if ($application === null || $application->assigned_officer_id === null) {
            return;
        }

        $officer = User::query()->with(['roles', 'permissions'])->whereKey($application->assigned_officer_id)->get()->toBase();

        $this->send($this->eligible($application, $officer, $row->user_id), new StaffNotification(
            self::DECIDED,
            'Online application decided',
            sprintf('%s from %s now reads %s.', $this->label($application), $this->applicant($application), ApplicationTransitions::label($row->to_status)),
            $this->url($application),
            ['application_id' => $application->id, 'loan_id' => $application->loan_id],
        ));
    }

    /**
     * @param  Collection<int, User>  $candidates
     * @return Collection<int, User>
     */
    private function eligible(OriginationApplication $application, Collection $candidates, ?int $actorId): Collection
    {
        $branchId = $application->branch_id === null ? null : (int) $application->branch_id;
        $actorId ??= auth()->id() === null ? null : (int) auth()->id();

        return $candidates
            ->filter(fn ($user) => $user instanceof User && $user->status === 'active')
            ->unique(fn (User $user) => (int) $user->getKey())
            ->reject(fn (User $user) => $actorId !== null && (int) $user->getKey() === $actorId)
            ->filter(fn (User $user) => $user->canAny(['loan_origination:view', 'loan_origination:update']))
            ->filter(fn (User $user) => $this->branches->canSee($user, $branchId))
            ->values();
    }

    /** @param  Collection<int, User>  $recipients */
    private function send(Collection $recipients, StaffNotification $notification): void
    {
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notification);
        }
    }

    /**
     * Run $send after the surrounding transaction commits (at once outside
     * one); log and swallow any failure.
     */
    private function later(?int $historyId, \Closure $send): void
    {
        try {
            DB::afterCommit(function () use ($historyId, $send): void {
                try {
                    $send();
                } catch (Throwable $e) {
                    $this->logFailure($historyId, $e);
                }
            });
        } catch (Throwable $e) {
            $this->logFailure($historyId, $e);
        }
    }

    private function logFailure(?int $historyId, Throwable $e): void
    {
        Log::error('An online application staff notice could not be sent; the change was kept.', [
            'origination_status_history_id' => $historyId,
            'exception' => $e,
        ]);
    }

    private function label(OriginationApplication $application): string
    {
        return $application->reference_number === null ? 'An online application' : "Application {$application->reference_number}";
    }

    private function applicant(OriginationApplication $application): string
    {
        return filled($application->applicant_name) ? (string) $application->applicant_name : 'an applicant';
    }

    private function url(OriginationApplication $application): string
    {
        return "/loans/origination/applications/{$application->id}";
    }
}
