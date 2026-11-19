<?php

namespace App\Services\Amla\Reports\Builders;

use App\Enums\PaymentMethod;
use App\Models\AmlaCtrCandidate;
use App\Models\AmlaEvent;
use App\Models\AmlaReport;
use App\Models\User;
use App\Services\Amla\Cases\FilingDeadlines;
use App\Services\Amla\Detection\DetectionScope;
use App\Services\Amla\Detection\Finding;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Reports\ComplianceReport;
use App\Services\Amla\Reports\ReportFilters;
use App\Services\Amla\Reports\ReportNames;
use Carbon\CarbonImmutable;

/** Report 1, Covered transactions: the CTR candidates of the period (transaction date), in the caller's branches. */
final class CoveredTransactions extends ComplianceReport
{
    public function __construct(private readonly DetectionScope $scope, private readonly FilingDeadlines $deadlines) {}

    public function key(): string
    {
        return 'covered_transactions';
    }

    public function title(): string
    {
        return 'Covered Transactions';
    }

    public function description(): string
    {
        return 'Each covered transaction (CTR) candidate, why it qualified, its filing status and deadline.';
    }

    public function permissions(): array
    {
        return [AmlaAccess::CTR];
    }

    public function columns(): array
    {
        return [
            ['key' => 'reference', 'label' => 'Reference', 'format' => 'text'],
            ['key' => 'customer', 'label' => 'Customer', 'format' => 'text'],
            ['key' => 'account', 'label' => 'Account', 'format' => 'text'],
            ['key' => 'branch', 'label' => 'Branch', 'format' => 'text'],
            ['key' => 'transaction', 'label' => 'Transaction type', 'format' => 'text'],
            ['key' => 'method', 'label' => 'Payment method', 'format' => 'text'],
            ['key' => 'amount', 'label' => 'Amount', 'format' => 'money'],
            ['key' => 'date', 'label' => 'Transaction date', 'format' => 'text'],
            ['key' => 'reason', 'label' => 'CTR eligibility reason', 'format' => 'text'],
            ['key' => 'filing_status', 'label' => 'Filing status', 'format' => 'text'],
            ['key' => 'deadline', 'label' => 'Reporting deadline', 'format' => 'text'],
            ['key' => 'submission_reference', 'label' => 'Submission reference', 'format' => 'text'],
        ];
    }

    public function rows(User $viewer, ReportFilters $filters): array
    {
        $candidates = $this->scope->constrain(AmlaCtrCandidate::query(), $viewer)
            ->whereBetween('occurred_on', [$filters->from, $filters->to])
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->with(['event', 'borrower', 'walkIn', 'branch'])->orderBy('occurred_on')->orderBy('id')->get();
        $reports = AmlaReport::query()->whereIn('ctr_candidate_id', $candidates->pluck('id'))->where('status', '!=', AmlaReport::RETURNED)->get()->keyBy('ctr_candidate_id');

        return $candidates->map(function (AmlaCtrCandidate $candidate) use ($reports) {
            $report = $reports->get($candidate->id);
            $due = $report === null ? $this->deadlines->candidateDue($candidate) : $this->deadlines->due($report);

            return [
                'reference' => $candidate->reference,
                'customer' => ReportNames::subject($candidate->borrower, $candidate->walkIn),
                'account' => Finding::NOT_AVAILABLE,
                'branch' => ReportNames::branch($candidate->branch),
                'transaction' => $candidate->event === null ? null : AmlaEvent::kindWords($candidate->event->kind),
                'method' => PaymentMethod::labelFor($candidate->channel),
                'amount' => (string) $candidate->amount,
                'date' => $candidate->occurred_on->format('M j, Y'),
                'reason' => $candidate->explanation,
                'filing_status' => $report === null ? AmlaCtrCandidate::STATUS_WORDS[$candidate->status] : AmlaReport::STATUS_WORDS[$report->status],
                'deadline' => CarbonImmutable::parse($due['due_on'])->format('M j, Y').($due['provisional'] ? ' (provisional)' : ''),
                'submission_reference' => $report?->submission_reference,
            ];
        })->values()->all();
    }
}
