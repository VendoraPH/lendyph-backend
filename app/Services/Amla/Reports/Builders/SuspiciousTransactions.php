<?php

namespace App\Services\Amla\Reports\Builders;

use App\Models\AmlaAlert;
use App\Models\AmlaCase;
use App\Models\AmlaEvent;
use App\Models\AmlaReport;
use App\Models\User;
use App\Services\Amla\Cases\FilingDeadlines;
use App\Services\Amla\Detection\DetectionRules;
use App\Services\Amla\Events\PesoAmount;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Reports\ComplianceReport;
use App\Services\Amla\Reports\ReportFilters;
use App\Services\Amla\Reports\ReportNames;
use Carbon\CarbonImmutable;

/**
 * Report 2, Suspicious transactions: cases whose decision established
 * suspicion in the period (determination date), with their STR's approval
 * and filing status. Head office only; never the STR narrative.
 */
final class SuspiciousTransactions extends ComplianceReport
{
    public function __construct(private readonly FilingDeadlines $deadlines, private readonly DetectionRules $rules) {}

    public function key(): string
    {
        return 'suspicious_transactions';
    }

    public function title(): string
    {
        return 'Suspicious Transactions';
    }

    public function description(): string
    {
        return 'Cases where suspicion was established, their related transactions, deadline and STR status. Never the narrative.';
    }

    public function permissions(): array
    {
        return [AmlaAccess::STR];
    }

    public function headOffice(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            ['key' => 'case', 'label' => 'Case reference', 'format' => 'text'],
            ['key' => 'customer', 'label' => 'Customer', 'format' => 'text'],
            ['key' => 'transactions', 'label' => 'Related transactions', 'format' => 'text'],
            ['key' => 'category', 'label' => 'Suspicious activity category', 'format' => 'text'],
            ['key' => 'status', 'label' => 'Investigation status', 'format' => 'text'],
            ['key' => 'determination_date', 'label' => 'Determination date', 'format' => 'text'],
            ['key' => 'deadline', 'label' => 'Filing deadline', 'format' => 'text'],
            ['key' => 'approval_status', 'label' => 'Approval status', 'format' => 'text'],
            ['key' => 'filing_status', 'label' => 'Filing status', 'format' => 'text'],
        ];
    }

    public function rows(User $viewer, ReportFilters $filters): array
    {
        $cases = AmlaCase::query()->where('decision', 'str')->whereBetween('determination_date', [$filters->from, $filters->to])
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->with(['borrower', 'walkIn', 'alerts.events'])->orderBy('determination_date')->orderBy('id')->get();
        $reports = AmlaReport::query()->whereIn('case_id', $cases->pluck('id'))->where('status', '!=', AmlaReport::RETURNED)->get()->keyBy('case_id');

        return $cases->map(function (AmlaCase $case) use ($reports) {
            $report = $reports->get($case->id);
            $events = $case->alerts->flatMap(fn (AmlaAlert $alert) => $alert->events)->unique('id')->whereNull('reversed_at');
            $total = $events->sum(fn (AmlaEvent $event) => PesoAmount::centavos((string) $event->amount));
            $due = $report === null ? $this->deadlines->caseDue($case) : $this->deadlines->due($report);

            return [
                'case' => $case->reference,
                'customer' => ReportNames::subject($case->borrower, $case->walkIn),
                'transactions' => $events->isEmpty() ? null : $events->count().' totalling '.PesoAmount::words(PesoAmount::fromCentavos($total)),
                'category' => $case->alerts->pluck('rule_code')->unique()->sort()->map(fn (string $code) => $this->rules->rule($code)->words())->implode(', ') ?: null,
                'status' => AmlaCase::STATUS_WORDS[$case->status] ?? $case->status,
                'determination_date' => $case->determination_date?->format('M j, Y'),
                'deadline' => CarbonImmutable::parse($due['due_on'])->format('M j, Y').($due['provisional'] ? ' (provisional)' : ''),
                'approval_status' => $report === null ? 'STR not prepared' : ($report->approved_by === null ? 'Waiting for approval' : 'Approved'),
                'filing_status' => $report === null ? 'Not filed' : AmlaReport::STATUS_WORDS[$report->status],
            ];
        })->values()->all();
    }
}
