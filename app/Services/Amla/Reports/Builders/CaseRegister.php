<?php

namespace App\Services\Amla\Reports\Builders;

use App\Models\AmlaAlert;
use App\Models\AmlaCase;
use App\Models\User;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Reports\ComplianceReport;
use App\Services\Amla\Reports\ReportFilters;
use App\Services\Amla\Reports\ReportNames;
use Carbon\CarbonImmutable;

/**
 * Report 7, Compliance case register: cases opened in the period or still
 * active, with their alerts, owner, highest alert priority, age, history and
 * decision. Head office only; never notes or the decision's reason.
 */
final class CaseRegister extends ComplianceReport
{
    private const PRIORITY_ORDER = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

    public function key(): string
    {
        return 'case_register';
    }

    public function title(): string
    {
        return 'Compliance Case Register';
    }

    public function description(): string
    {
        return 'Every case opened in the period or still open: its alerts, owner, priority, age, history and decision.';
    }

    public function permissions(): array
    {
        return [AmlaAccess::CASES];
    }

    public function headOffice(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            ['key' => 'case', 'label' => 'Case number', 'format' => 'text'],
            ['key' => 'alerts', 'label' => 'Alert origin', 'format' => 'text'],
            ['key' => 'owner', 'label' => 'Case owner', 'format' => 'text'],
            ['key' => 'priority', 'label' => 'Priority', 'format' => 'text'],
            ['key' => 'summary', 'label' => 'Investigation summary', 'format' => 'text'],
            ['key' => 'age_days', 'label' => 'Case age (days)', 'format' => 'number'],
            ['key' => 'history', 'label' => 'Escalation history', 'format' => 'text'],
            ['key' => 'decision', 'label' => 'Decision', 'format' => 'text'],
            ['key' => 'closed_on', 'label' => 'Closure', 'format' => 'text'],
        ];
    }

    public function rows(User $viewer, ReportFilters $filters): array
    {
        $cases = AmlaCase::query()
            ->where(fn ($q) => $q->whereBetween('created_at', $filters->moments())->orWhereIn('status', AmlaCase::ACTIVE))
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->with(['alerts', 'assignee', 'actions'])->orderBy('id')->get();
        $today = CarbonImmutable::now('Asia/Manila')->startOfDay();

        return $cases->map(function (AmlaCase $case) use ($today) {
            $priority = $case->alerts->sortByDesc(fn (AmlaAlert $alert) => self::PRIORITY_ORDER[$alert->priority] ?? 0)->first()?->priority;
            $end = $case->closed_at === null ? $today : CarbonImmutable::parse($case->closed_at)->startOfDay();

            return [
                'case' => $case->reference,
                'alerts' => $case->alerts->pluck('reference')->implode(', ') ?: 'Opened directly',
                'owner' => ReportNames::user($case->assignee) ?? 'Unassigned',
                'priority' => $priority === null ? null : (AmlaAlert::PRIORITY_WORDS[$priority] ?? ucfirst($priority)),
                'summary' => $case->title,
                'age_days' => (int) CarbonImmutable::parse($case->created_at)->startOfDay()->diffInDays($end),
                'history' => $case->actions->count().' step'.($case->actions->count() === 1 ? '' : 's'),
                'decision' => match ($case->decision) {
                    'str' => 'Suspicion established',
                    'no_str' => 'No STR',
                    default => 'Not decided',
                },
                'closed_on' => $case->closed_at === null ? 'Open' : CarbonImmutable::parse($case->closed_at)->format('M j, Y').' ('.(AmlaCase::STATUS_WORDS[$case->status] ?? $case->status).')',
            ];
        })->values()->all();
    }
}
