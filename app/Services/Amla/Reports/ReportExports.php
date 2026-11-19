<?php

namespace App\Services\Amla\Reports;

use App\Models\AmlaExport;
use App\Models\User;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\SignedFileLink;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Makes an AMLA report export on the server, keeps it on the private disk
 * for the user who asked, writes the AMLA log, and answers with a signed
 * link (SignedFileLink, bound to that user). Writes AMLA tables only.
 */
final class ReportExports
{
    public const DISK = 'private';

    public function __construct(
        private readonly AmlaReports $reports,
        private readonly ReportRenderer $renderer,
        private readonly AmlaLog $log,
    ) {}

    public function make(User $user, ComplianceReport $report, ReportFilters $filters, string $format): AmlaExport
    {
        $table = $this->reports->build($report, $user, $filters);
        $body = $this->renderer->render($table, $format, $user);
        $path = 'amla/exports/'.Str::uuid()->toString().'.'.$format;
        Storage::disk(self::DISK)->put($path, $body);

        $export = AmlaExport::query()->create([
            'report_key' => $report->key(),
            'format' => $format,
            'filters' => $filters->toArray(),
            'title' => $report->title(),
            'is_draft' => $table->isDraft(),
            'file_path' => $path,
            'file_size' => strlen($body),
            'sha256' => hash('sha256', $body),
            'row_count' => count($table->rows),
            'user_id' => $user->id,
        ]);

        $this->log->record('amla_report_exported', $user, 'amla_export', $export->id, [
            'report' => $report->key(), 'format' => $format, 'rows' => count($table->rows), ...$filters->toArray(),
        ]);

        return $export;
    }

    public function link(AmlaExport $export): string
    {
        return SignedFileLink::to('amla.exports.download', ['id' => $export->id]);
    }

    public function downloadName(AmlaExport $export): string
    {
        return Str::slug($export->title).'-'.$export->created_at?->format('Ymd-His').'.'.$export->format;
    }
}
