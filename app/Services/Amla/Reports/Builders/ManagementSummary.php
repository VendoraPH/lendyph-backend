<?php

namespace App\Services\Amla\Reports\Builders;

use App\Models\AmlaAlert;
use App\Models\AmlaCase;
use App\Models\AmlaEvent;
use App\Models\AmlaEventFailure;
use App\Models\AmlaEventScreening;
use App\Models\AmlaReport;
use App\Models\AmlaScreeningMatch;
use App\Models\User;
use App\Services\Amla\Cases\FilingDeadlines;
use App\Services\Amla\Cdd\CddWorklist;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Reports\ComplianceReport;
use App\Services\Amla\Reports\ReportFilters;
use Carbon\CarbonImmutable;

/**
 * Report 10, AMLA management summary: the whole cooperative's figures for
 * the period and as of today, for management. Aggregates only: no names,
 * no case notes and never an STR narrative. Head office only.
 */
final class ManagementSummary extends ComplianceReport
{
    public function __construct(private readonly FilingDeadlines $deadlines, private readonly CddWorklist $worklist) {}

    public function key(): string
    {
        return 'management_summary';
    }

    public function title(): string
    {
        return 'AMLA Management Summary';
    }

    public function description(): string
    {
        return 'Transactions monitored, alerts, investigations, filings, missed deadlines, KYC and watchlist reviews, as totals only.';
    }

    public function permissions(): array
    {
        return [AmlaAccess::VIEW];
    }

    public function headOffice(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            ['key' => 'metric', 'label' => 'Measure', 'format' => 'text'],
            ['key' => 'value', 'label' => 'Figure', 'format' => 'text'],
        ];
    }

    public function rows(User $viewer, ReportFilters $filters): array
    {
        $moments = $filters->moments();
        $events = AmlaEvent::query()->where('is_internal', false)->whereBetween('occurred_on', [$filters->from, $filters->to]);
        $monitored = (clone $events)->count();
        $screened = (clone $events)->whereIn('id', AmlaEventScreening::query()->select('event_id'))->count();
        $today = CarbonImmutable::now('Asia/Manila')->toDateString();
        $missed = collect($this->deadlines->filings())->filter(fn (array $filing) => $filing['due']['due_on'] < $today)->count();
        $subjects = $this->worklist->subjects($viewer);
        $verified = collect($subjects)->filter(fn (array $entry) => $entry['state']['status'] === 'verified')->count();
        $byStatus = fn (string $type) => AmlaReport::query()->where('type', $type)->get(['status'])->countBy('status')
            ->map(fn (int $count, string $status) => (AmlaReport::STATUS_WORDS[$status] ?? $status).': '.$count)->sort()->implode(', ') ?: 'None';

        $rows = [
            ['metric' => 'Transactions monitored', 'value' => (string) $monitored],
            ['metric' => 'Screened', 'value' => (string) $screened],
            ['metric' => 'Unscreened', 'value' => (string) ($monitored - $screened)],
            ['metric' => 'Alerts raised in the period', 'value' => (string) AmlaAlert::query()->whereBetween('created_at', $moments)->count()],
            ['metric' => 'Open alerts', 'value' => (string) AmlaAlert::query()->whereIn('status', AmlaAlert::WORKABLE)->count()],
            ['metric' => 'Open investigations', 'value' => (string) AmlaCase::query()->whereIn('status', AmlaCase::ACTIVE)->count()],
            ['metric' => 'Resolved cases in the period', 'value' => (string) AmlaCase::query()->whereBetween('closed_at', $moments)->count()],
            ['metric' => 'CTR filing status', 'value' => $byStatus(AmlaReport::CTR)],
            ['metric' => 'STR filing status', 'value' => $byStatus(AmlaReport::STR)],
            ['metric' => 'Missed deadlines', 'value' => (string) $missed],
            ['metric' => 'KYC completion', 'value' => $verified.' of '.count($subjects).' customers verified'],
            ['metric' => 'Outstanding watchlist reviews', 'value' => (string) AmlaScreeningMatch::query()->where('status', AmlaScreeningMatch::PENDING)->count()],
            ['metric' => 'Transactions not yet harvested (failures)', 'value' => (string) AmlaEventFailure::query()->whereNull('resolved_at')->count()],
        ];

        return $rows;
    }

    public function usesPeriod(): bool
    {
        return true;
    }
}
