<?php

namespace App\Services\Amla\Reports\Builders;

use App\Models\AmlaLogEntry;
use App\Models\User;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\Amla\Reports\ComplianceReport;
use App\Services\Amla\Reports\ReportFilters;
use App\Services\Amla\Reports\ReportNames;
use Illuminate\Validation\ValidationException;

/**
 * Report 9, Compliance audit and history: AMLA's own log for the period
 * (settings and rule changes, alert decisions, case assignments, approvals,
 * file generation, submissions, access and exports), in words. Head office
 * only; the entries' details are never printed.
 */
final class AuditHistory extends ComplianceReport
{
    /** The most entries one report holds (`amla.audit_report_max_rows`); a bigger period is refused, never cut short. */
    public static function maxRows(): int
    {
        return (int) config('amla.audit_report_max_rows', 5000);
    }

    public function assertFits(ReportFilters $filters): void
    {
        $count = AmlaLogEntry::query()->whereBetween('created_at', $filters->moments())->count();

        if ($count > self::maxRows()) {
            throw ValidationException::withMessages(['from' => sprintf(
                'Narrow the dates: this period has %s entries, and one report holds at most %s.', number_format($count), number_format(self::maxRows()),
            )]);
        }
    }

    public function key(): string
    {
        return 'audit_history';
    }

    public function title(): string
    {
        return 'Compliance Audit and History';
    }

    public function description(): string
    {
        return 'Every AMLA action in the period: changes, decisions, approvals, files, submissions, access and exports.';
    }

    public function permissions(): array
    {
        return [AmlaAccess::AUDIT];
    }

    public function headOffice(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            ['key' => 'at', 'label' => 'When (Manila)', 'format' => 'text'],
            ['key' => 'action', 'label' => 'Action', 'format' => 'text'],
            ['key' => 'by', 'label' => 'By', 'format' => 'text'],
            ['key' => 'subject', 'label' => 'Record', 'format' => 'text'],
        ];
    }

    public function rows(User $viewer, ReportFilters $filters): array
    {
        $entries = AmlaLogEntry::query()->with('user')->whereBetween('created_at', $filters->moments())->orderBy('id')->get();
        $references = AmlaLog::references($entries);

        return $entries
            ->map(fn (AmlaLogEntry $entry) => [
                'at' => $entry->created_at?->format('M j, Y g:i A'),
                'action' => AmlaLog::actionWords($entry->action),
                'by' => $entry->actor_type === 'system' ? 'System' : ReportNames::user($entry->user),
                'subject' => AmlaLog::subjectWords($entry->subject_type, $entry->subject_id, $references["{$entry->subject_type}:{$entry->subject_id}"] ?? null),
            ])->values()->all();
    }
}
