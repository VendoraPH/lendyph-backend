<?php

namespace App\Services\Cda\Reports;

use App\Models\CdaReport;
use App\Models\CdaReportApprovalStep;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffNotification;
use App\Services\BranchAccess;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\ReportTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * In-app notices for the CDA workflow (design §5, §10): approval needed,
 * returned, finalized, for correction and submission recorded. Staff only,
 * database channel only, never SMS or email.
 *
 * Recipients are active users who hold `branches:view_all` and the
 * audience's permission (`cda:approve` for an approval; `cda:prepare` for a
 * return; both for finalized and for correction; `cda:view` for a recorded
 * filing), plus the report's
 * restricted view where it has one, through an active role or directly
 * (admin and super_admin always count), never the person who acted. On a
 * review chain (gap round B6) the approval notice goes only to holders of
 * the pending step's role, never the submitter or anyone who approved an
 * earlier step of the round. Sent
 * after the transition commits, so a refused or rolled-back action tells
 * nobody; a failure to send is logged and never undoes the transition.
 */
final class WorkflowNotices
{
    public const APPROVAL_NEEDED = 'cda_approval_needed';

    public const RETURNED = 'cda_report_returned';

    public const FINALIZED = 'cda_report_finalized';

    public const FOR_CORRECTION = 'cda_report_for_correction';

    public const SUBMISSION_RECORDED = 'cda_submission_recorded';

    /** The screen of each report type. */
    private const PATHS = [
        'capr' => '/cda/capr', 'financial_statements' => '/cda/financial-statements', 'performance_audit' => '/cda/performance-audit',
        'social_audit' => '/cda/social-audit', 'pesos' => '/cda/pesos', 'officers_trainings' => '/cda/officers',
        'mediation' => '/cda/mediation', 'sworn_statement' => '/cda/sworn-statement', 'atir' => '/cda/tax-reports', 'abr' => '/cda/tax-reports',
    ];

    /**
     * With a step, only the users who can act on it hear: active holders of
     * its role, never the submitter or an earlier step's approver.
     *
     * @param  list<int>  $skip  further users never told (an earlier step's approvers)
     */
    public function approvalNeeded(CdaReport $report, User $actor, ?CdaReportApprovalStep $step = null, array $skip = []): void
    {
        if ($step === null) {
            $this->send($report, $actor, self::APPROVAL_NEEDED, [CdaAccess::APPROVE], 'CDA report awaiting approval', '%s was submitted for review by %s.');

            return;
        }

        $role = $step->role;
        $skip = [...$skip, (int) $report->submitted_for_review_by];
        $narrow = fn (Builder $users) => $users->whereNotIn('id', $skip)
            ->whereHas('roles', fn (Builder $roles) => $roles->where('roles.name', $role)
                ->where(fn (Builder $active) => $active->where('roles.is_active', true)->orWhereIn('roles.name', Role::PROTECTED_KEYS)));

        $this->send(
            $report, $actor, self::APPROVAL_NEEDED, [CdaAccess::APPROVE], 'CDA report awaiting approval',
            '%s is waiting for your approval (sent on by %s). Review step: %s.', [$step->name],
            $narrow, ['step' => $step->name, 'step_order' => (int) $step->step_order],
        );
    }

    /** A filing recorded (mark_submitted): everyone who can see the report hears. */
    public function submissionRecorded(CdaReport $report, User $actor, string $submittedOn): void
    {
        $this->send(
            $report, $actor, self::SUBMISSION_RECORDED, [CdaAccess::VIEW], 'CDA filing recorded',
            '%s was recorded as filed with CDA by %s (filed on %s).', [Carbon::parse($submittedOn)->format('M j, Y')],
            null, ['submitted_on' => $submittedOn],
        );
    }

    public function returned(CdaReport $report, User $actor, string $reason): void
    {
        $this->send($report, $actor, self::RETURNED, [CdaAccess::PREPARE], 'CDA report returned', '%s was returned to draft by %s. Reason: %s', [$reason]);
    }

    public function finalized(CdaReport $report, User $actor): void
    {
        $this->send($report, $actor, self::FINALIZED, [CdaAccess::PREPARE, CdaAccess::APPROVE], 'CDA report finalized', '%s was finalized by %s and is ready to file.');
    }

    public function forCorrection(CdaReport $report, User $actor, string $reason): void
    {
        $this->send($report, $actor, self::FOR_CORRECTION, [CdaAccess::PREPARE, CdaAccess::APPROVE], 'CDA report needs correction', '%s needs correction, recorded by %s. Reason: %s', [$reason]);
    }

    /**
     * The body is a fixed format: the report and the actor fill its first two
     * `%s`, and anything staff typed (a reason) only ever goes in as a later
     * argument, never into the format itself.
     *
     * @param  list<string>  $audience
     * @param  list<string>  $extra  further arguments for the body
     * @param  (callable(Builder): mixed)|null  $narrow  a further limit on the recipients
     * @param  array<string, mixed>  $data  further data for the notice
     */
    private function send(CdaReport $report, User $actor, string $type, array $audience, string $title, string $body, array $extra = [], ?callable $narrow = null, array $data = []): void
    {
        $deliver = function () use ($report, $actor, $type, $audience, $title, $body, $extra, $narrow, $data): void {
            try {
                $recipients = $this->recipients($report, $audience, (int) $actor->id, $narrow);

                if ($recipients->isEmpty()) {
                    return;
                }

                $year = (int) $report->period()->value('year');
                $name = ReportValidator::reportName($report)." {$year}";

                Notification::send($recipients, new StaffNotification(
                    $type,
                    $title,
                    sprintf($body, $name, trim((string) $actor->full_name) ?: (string) $actor->username, ...$extra),
                    (self::PATHS[$report->report_type] ?? '/cda').'?year='.$year,
                    ['report_id' => (int) $report->id, 'report_type' => $report->report_type, 'part' => $report->part, 'year' => $year, ...$data],
                ));
            } catch (Throwable $e) {
                Log::warning('CDA workflow notice not sent.', ['report_id' => $report->id, 'type' => $type, 'error' => $e->getMessage()]);
            }
        };

        DB::afterCommit($deliver);
    }

    /**
     * @param  list<string>  $audience
     * @param  (callable(Builder): mixed)|null  $narrow
     * @return Collection<int, User>
     */
    private function recipients(CdaReport $report, array $audience, int $actorId, ?callable $narrow = null): Collection
    {
        $needed = [BranchAccess::VIEW_ALL];
        $restriction = ReportTypes::restriction($report->report_type);

        if ($restriction !== null) {
            $needed[] = $restriction;
        }

        $holds = fn (string $permission) => fn (Builder $users) => $users
            ->whereHas('roles', fn (Builder $roles) => $roles
                ->where(fn (Builder $active) => $active->where('roles.is_active', true)->orWhereIn('roles.name', Role::PROTECTED_KEYS))
                ->whereHas('permissions', fn (Builder $p) => $p->where('name', $permission)))
            ->orWhereHas('permissions', fn (Builder $p) => $p->where('name', $permission));

        $query = User::query()->active()->where('id', '!=', $actorId);

        foreach ($needed as $permission) {
            $query->where($holds($permission));
        }

        $query->where(function (Builder $any) use ($audience, $holds): void {
            foreach ($audience as $permission) {
                $any->orWhere($holds($permission));
            }
        });

        if ($narrow !== null) {
            $narrow($query);
        }

        return $query->orderBy('id')->get();
    }
}
