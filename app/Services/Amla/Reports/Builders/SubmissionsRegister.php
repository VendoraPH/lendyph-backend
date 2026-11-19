<?php

namespace App\Services\Amla\Reports\Builders;

use App\Models\AmlaReport;
use App\Models\AmlaReportSubmission;
use App\Models\User;
use App\Services\Amla\Cases\FilingDeadlines;
use App\Services\Amla\Cases\Filings;
use App\Services\Amla\Reports\ComplianceReport;
use App\Services\Amla\Reports\ReportFilters;
use Carbon\CarbonImmutable;

/**
 * Report 8, Regulatory submissions register: the CTR and STR records
 * prepared in the period that the caller may read (CTRs in their branches,
 * STRs with head-office access), with their deadline and filing history.
 */
final class SubmissionsRegister extends ComplianceReport
{
    public function __construct(private readonly Filings $filings, private readonly FilingDeadlines $deadlines) {}

    public function key(): string
    {
        return 'submissions_register';
    }

    public function title(): string
    {
        return 'Regulatory Submissions Register';
    }

    public function description(): string
    {
        return 'Each CTR and STR record: its period, status, deadline, submission and the facility\'s responses.';
    }

    public function permissions(): array
    {
        return array_values(array_unique([...Filings::CTR_READ, ...Filings::STR_READ]));
    }

    public function columns(): array
    {
        return [
            ['key' => 'reference', 'label' => 'Reference', 'format' => 'text'],
            ['key' => 'type', 'label' => 'Report type', 'format' => 'text'],
            ['key' => 'period', 'label' => 'Reporting period', 'format' => 'text'],
            ['key' => 'status', 'label' => 'Preparation status', 'format' => 'text'],
            ['key' => 'deadline', 'label' => 'Filing deadline', 'format' => 'text'],
            ['key' => 'submitted_on', 'label' => 'Submission date', 'format' => 'text'],
            ['key' => 'submission_reference', 'label' => 'Submission reference', 'format' => 'text'],
            ['key' => 'acceptance', 'label' => 'Acceptance status', 'format' => 'text'],
            ['key' => 'corrections', 'label' => 'Rejection and correction history', 'format' => 'text'],
        ];
    }

    public function rows(User $viewer, ReportFilters $filters): array
    {
        $reports = $this->filings->visibleQuery($viewer)->whereBetween('created_at', $filters->moments())
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->with(['submissions'])->orderBy('id')->get();

        return $reports->map(function (AmlaReport $report) {
            $due = $this->deadlines->due($report);
            $steps = $report->submissions->sortBy('id');
            $responses = $steps->whereIn('status', [AmlaReport::ACCEPTED, AmlaReport::REJECTED, AmlaReport::CORRECTION_REQUIRED]);
            $corrections = $steps->whereIn('status', [AmlaReport::REJECTED, AmlaReport::CORRECTION_REQUIRED]);

            return [
                'reference' => $report->reference,
                'type' => AmlaReport::TYPE_WORDS[$report->type],
                'period' => $report->deadline_from->format('F Y'),
                'status' => AmlaReport::STATUS_WORDS[$report->status] ?? $report->status,
                'deadline' => CarbonImmutable::parse($due['due_on'])->format('M j, Y').($due['provisional'] ? ' (provisional)' : ''),
                'submitted_on' => $report->submitted_on?->format('M j, Y'),
                'submission_reference' => $report->submission_reference,
                'acceptance' => $responses->isEmpty() ? null : AmlaReport::STATUS_WORDS[$responses->last()->status],
                'corrections' => $corrections->isEmpty() ? 'None' : $corrections->map(fn (AmlaReportSubmission $step) => AmlaReport::STATUS_WORDS[$step->status].' '.$step->occurred_on->format('M j, Y'))->implode('; '),
            ];
        })->values()->all();
    }
}
