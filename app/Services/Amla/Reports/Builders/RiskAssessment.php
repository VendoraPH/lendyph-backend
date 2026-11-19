<?php

namespace App\Services\Amla\Reports\Builders;

use App\Models\AmlaRiskAssessment;
use App\Models\Borrower;
use App\Models\User;
use App\Services\Amla\Cdd\CddWorklist;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Reports\ComplianceReport;
use App\Services\Amla\Reports\ReportFilters;
use App\Services\Amla\Reports\ReportNames;
use Carbon\CarbonImmutable;

/**
 * Report 4, Customer AML risk assessment: each subject in the caller's scope
 * with an AMLA profile, as of today: the level, when it was assessed, the
 * factors that scored, how the level changed, and the review status.
 */
final class RiskAssessment extends ComplianceReport
{
    public function __construct(private readonly CddWorklist $worklist) {}

    public function key(): string
    {
        return 'risk_assessment';
    }

    public function title(): string
    {
        return 'Customer AML Risk Assessment';
    }

    public function description(): string
    {
        return 'Each customer\'s AML risk level, its scored factors, changes and review dates.';
    }

    public function permissions(): array
    {
        return [AmlaAccess::CDD];
    }

    public function usesPeriod(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [
            ['key' => 'customer', 'label' => 'Customer', 'format' => 'text'],
            ['key' => 'branch', 'label' => 'Branch', 'format' => 'text'],
            ['key' => 'risk_level', 'label' => 'Risk level', 'format' => 'text'],
            ['key' => 'assessed_on', 'label' => 'Assessment date', 'format' => 'text'],
            ['key' => 'factors', 'label' => 'Risk factors', 'format' => 'text'],
            ['key' => 'changes', 'label' => 'Risk changes', 'format' => 'text'],
            ['key' => 'review_status', 'label' => 'Review status', 'format' => 'text'],
            ['key' => 'next_review_on', 'label' => 'Next review date', 'format' => 'text'],
        ];
    }

    public function rows(User $viewer, ReportFilters $filters): array
    {
        $today = CarbonImmutable::now('Asia/Manila')->toDateString();
        $rows = [];

        foreach ($this->worklist->subjects($viewer, ['branch_id' => $filters->branchId]) as $entry) {
            $profile = $entry['profile'];

            if ($profile === null) {
                continue;
            }

            $assessments = $profile->riskAssessments()->orderBy('id')->get(['id', 'level', 'factors', 'overridden', 'created_at']);
            $latest = $assessments->last();
            $levels = $assessments->pluck('level')->values();
            $changes = $levels->filter(fn ($level, $i) => $i > 0 && $level !== $levels[$i - 1])->count();
            $next = $profile->next_review_on?->toDateString();

            $rows[] = [
                'customer' => ReportNames::subject($entry['subject'] instanceof Borrower ? $entry['subject'] : null, $entry['subject'] instanceof Borrower ? null : $entry['subject']),
                'branch' => $entry['subject'] instanceof Borrower ? ReportNames::branch($entry['subject']->branch) : ReportNames::ORGANISATION_WIDE,
                'risk_level' => $latest === null ? null : (AmlaRiskAssessment::LEVELS[$latest->level] ?? ucfirst($latest->level)).($latest->overridden ? ' (override)' : ''),
                'assessed_on' => $latest?->created_at?->format('M j, Y'),
                'factors' => $latest === null ? null : (collect((array) $latest->factors)->filter(fn ($factor) => (int) ($factor['points'] ?? 0) > 0)
                    ->map(fn ($factor) => ($factor['label'] ?? 'Factor').': '.($factor['value_words'] ?? '').' ('.(int) $factor['points'].')')->implode(', ') ?: 'None scored'),
                'changes' => $changes === 0 ? 'No change' : $changes.' change'.($changes === 1 ? '' : 's').', first '.ucfirst((string) $levels->first()),
                'review_status' => $next === null ? 'Not scheduled' : ($next < $today ? 'Review overdue' : 'Up to date'),
                'next_review_on' => $next === null ? null : CarbonImmutable::parse($next)->format('M j, Y'),
            ];
        }

        return $rows;
    }
}
