<?php

namespace App\Services\Cda\Reports;

use App\Models\CdaReport;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffNotification;
use App\Services\BranchAccess;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\ReportTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * In-app notices for the CDA workflow (design §5, §10): approval needed,
 * returned, finalized and for correction. Staff only, database channel only,
 * never SMS or email.
 *
 * Recipients are active users who hold `branches:view_all` and the
 * audience's permission (`cda:approve` for an approval; `cda:prepare` for a
 * return; both for finalized and for correction), plus the report's
 * restricted view where it has one, through an active role or directly
 * (admin and super_admin always count), never the person who acted. Sent
 * after the transition commits, so a refused or rolled-back action tells
 * nobody; a failure to send is logged and never undoes the transition.
 */
final class WorkflowNotices
{
    public const APPROVAL_NEEDED = 'cda_approval_needed';

    public const RETURNED = 'cda_report_returned';

    public const FINALIZED = 'cda_report_finalized';

    public const FOR_CORRECTION = 'cda_report_for_correction';

    /** The screen of each report type. */
    private const PATHS = [
        'capr' => '/cda/capr', 'financial_statements' => '/cda/financial-statements', 'performance_audit' => '/cda/performance-audit',
        'social_audit' => '/cda/social-audit', 'pesos' => '/cda/pesos', 'officers_trainings' => '/cda/officers',
        'mediation' => '/cda/mediation', 'sworn_statement' => '/cda/sworn-statement', 'atir' => '/cda/tax-reports', 'abr' => '/cda/tax-reports',
    ];

    public function approvalNeeded(CdaReport $report, User $actor): void
    {
        $this->send($report, $actor, self::APPROVAL_NEEDED, [CdaAccess::APPROVE], 'CDA report awaiting approval', '%s was submitted for review by %s.');
    }

    public function returned(CdaReport $report, User $actor, string $reason): void
    {
        $this->send($report, $actor, self::RETURNED, [CdaAccess::PREPARE], 'CDA report returned', '%s was returned to draft by %s. Reason: '.$reason);
    }

    public function finalized(CdaReport $report, User $actor): void
    {
        $this->send($report, $actor, self::FINALIZED, [CdaAccess::PREPARE, CdaAccess::APPROVE], 'CDA report finalized', '%s was finalized by %s and is ready to file.');
    }

    public function forCorrection(CdaReport $report, User $actor, string $reason): void
    {
        $this->send($report, $actor, self::FOR_CORRECTION, [CdaAccess::PREPARE, CdaAccess::APPROVE], 'CDA report needs correction', '%s needs correction, recorded by %s. Reason: '.$reason);
    }

    /** @param  list<string>  $audience */
    private function send(CdaReport $report, User $actor, string $type, array $audience, string $title, string $body): void
    {
        $deliver = function () use ($report, $actor, $type, $audience, $title, $body): void {
            try {
                $recipients = $this->recipients($report, $audience, (int) $actor->id);

                if ($recipients->isEmpty()) {
                    return;
                }

                $year = (int) $report->period()->value('year');
                $name = ReportValidator::reportName($report)." {$year}";

                Notification::send($recipients, new StaffNotification(
                    $type,
                    $title,
                    sprintf($body, $name, trim((string) $actor->full_name) ?: (string) $actor->username),
                    (self::PATHS[$report->report_type] ?? '/cda').'?year='.$year,
                    ['report_id' => (int) $report->id, 'report_type' => $report->report_type, 'part' => $report->part, 'year' => $year],
                ));
            } catch (Throwable $e) {
                Log::warning('CDA workflow notice not sent.', ['report_id' => $report->id, 'type' => $type, 'error' => $e->getMessage()]);
            }
        };

        DB::afterCommit($deliver);
    }

    /**
     * @param  list<string>  $audience
     * @return Collection<int, User>
     */
    private function recipients(CdaReport $report, array $audience, int $actorId): Collection
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

        return $query->orderBy('id')->get();
    }
}
