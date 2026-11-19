<?php

namespace App\Services\Amla\Reports;

use App\Models\AmlaAlert;
use App\Models\AmlaCase;
use App\Models\AmlaCtrCandidate;
use App\Models\User;
use App\Services\Amla\Cases\FilingDeadlines;
use App\Services\Amla\Cases\Filings;
use App\Services\Amla\Cdd\CddState;
use App\Services\Amla\Cdd\CddWorklist;
use App\Services\Amla\Detection\DetectionScope;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\BranchAccess;
use Carbon\CarbonImmutable;

/**
 * The AMLA dashboard's counts (AMLA-B7): each block only for a holder of its
 * own key (null otherwise), in the caller's scope, as AMLA screens count it:
 * - alerts (`amla:alerts`): open and in review, by priority;
 * - CTR candidates (`amla:ctr`): by filing status;
 * - cases (`amla:cases` with head-office access): by status;
 * - filings (any reporting key): due soon, today and overdue;
 * - CDD (`amla:cdd`): the worklist by status, worked out now.
 */
final class DashboardCounts
{
    public function __construct(
        private readonly DetectionScope $scope,
        private readonly FilingDeadlines $deadlines,
        private readonly CddWorklist $worklist,
        private readonly BranchAccess $branches,
    ) {}

    /** @return array<string, array<string, int>|null> */
    public function for(User $user): array
    {
        return [
            'alerts' => $user->can(AmlaAccess::ALERTS) ? $this->alerts($user) : null,
            'ctr_candidates' => $user->can(AmlaAccess::CTR) ? $this->candidates($user) : null,
            'cases' => $user->can(AmlaAccess::CASES) && $user->can(BranchAccess::VIEW_ALL) ? $this->cases() : null,
            'filings' => $user->canAny([...Filings::CTR_READ, ...Filings::STR_READ]) ? $this->filings($user) : null,
            'cdd' => $user->can(AmlaAccess::CDD) ? $this->cdd($user) : null,
        ];
    }

    /** @return array<string, int> */
    private function alerts(User $user): array
    {
        $byPriority = $this->scope->constrainAlerts(AmlaAlert::query(), $user)->whereIn('status', AmlaAlert::WORKABLE)
            ->get(['priority'])->countBy('priority');
        $counts = [];

        foreach (array_keys(AmlaAlert::PRIORITY_WORDS) as $priority) {
            $counts[$priority] = (int) ($byPriority[$priority] ?? 0);
        }

        return [...$counts, 'total' => array_sum($counts)];
    }

    /** @return array<string, int> */
    private function candidates(User $user): array
    {
        $byStatus = $this->scope->constrain(AmlaCtrCandidate::query(), $user)->get(['status'])->countBy('status');
        $counts = [];

        foreach (array_keys(AmlaCtrCandidate::STATUS_WORDS) as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }

        return $counts;
    }

    /** @return array<string, int> */
    private function cases(): array
    {
        $byStatus = AmlaCase::query()->get(['status'])->countBy('status');
        $counts = [];

        foreach (array_keys(AmlaCase::STATUS_WORDS) as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }

        return $counts;
    }

    /** @return array<string, int> */
    private function filings(User $user): array
    {
        $today = CarbonImmutable::now('Asia/Manila')->toDateString();
        $counts = ['upcoming' => 0, 'due_today' => 0, 'overdue' => 0];

        foreach ($this->deadlines->filings() as $filing) {
            $keys = $filing['type'] === 'str' ? Filings::STR_READ : Filings::CTR_READ;
            $visible = $user->canAny($keys) && ($filing['head_office'] ? $user->can(BranchAccess::VIEW_ALL) : $this->branches->canSee($user, $filing['branch_id']));
            $standing = $visible ? $this->deadlines->standing($filing['due']['due_on'], $today) : null;

            if ($standing !== null) {
                $counts[$standing]++;
            }
        }

        return $counts;
    }

    /** @return array<string, int> */
    private function cdd(User $user): array
    {
        $counts = array_fill_keys(array_keys(CddState::STATUSES), 0);

        foreach ($this->worklist->subjects($user) as $entry) {
            $counts[$entry['state']['status']] = ($counts[$entry['state']['status']] ?? 0) + 1;
        }

        return $counts;
    }
}
