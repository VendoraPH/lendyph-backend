<?php

namespace App\Services\Amla\Reports;

use App\Models\User;
use App\Services\Amla\Reports\Builders\AuditHistory;
use App\Services\Amla\Reports\Builders\CaseRegister;
use App\Services\Amla\Reports\Builders\CoveredTransactions;
use App\Services\Amla\Reports\Builders\CustomerDueDiligence;
use App\Services\Amla\Reports\Builders\ManagementSummary;
use App\Services\Amla\Reports\Builders\RiskAssessment;
use App\Services\Amla\Reports\Builders\SubmissionsRegister;
use App\Services\Amla\Reports\Builders\SuspiciousTransactions;
use App\Services\Amla\Reports\Builders\TransactionMonitoring;
use App\Services\Amla\Reports\Builders\WatchlistScreening;

/**
 * The ten AMLA reports (task §16; AMLA-B7), in the task's order. Each states
 * its own keys; the catalog lists only the ones the user may open.
 */
final class AmlaReports
{
    /** @var list<class-string<ComplianceReport>> */
    private const REPORTS = [
        CoveredTransactions::class, SuspiciousTransactions::class, TransactionMonitoring::class, RiskAssessment::class,
        CustomerDueDiligence::class, WatchlistScreening::class, CaseRegister::class, SubmissionsRegister::class,
        AuditHistory::class, ManagementSummary::class,
    ];

    /** @return array<string, ComplianceReport> */
    public function all(): array
    {
        $reports = [];

        foreach (self::REPORTS as $class) {
            $report = app($class);
            $reports[$report->key()] = $report;
        }

        return $reports;
    }

    public function find(string $key): ?ComplianceReport
    {
        return $this->all()[$key] ?? null;
    }

    /** @return list<ComplianceReport> */
    public function visibleTo(User $user): array
    {
        return array_values(array_filter($this->all(), fn (ComplianceReport $report) => $report->canSee($user)));
    }

    public function build(ComplianceReport $report, User $user, ReportFilters $filters): ReportTable
    {
        $report->assertFits($filters);
        $rows = $report->rows($user, $filters);

        return new ReportTable($report, $filters, $report->columns(), $rows, $report->totals($rows));
    }
}
