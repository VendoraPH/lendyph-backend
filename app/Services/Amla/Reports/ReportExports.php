<?php

namespace App\Services\Amla\Reports;

use App\Models\AmlaExport;
use App\Models\User;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\SignedFileLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Makes an AMLA report export on the server, keeps it on the private disk
 * for the user who asked until its link expires (then pruneExpired() deletes
 * the file, keeping the record), writes the AMLA log, and answers with a signed
 * link (SignedFileLink, bound to that user). Writes AMLA tables only.
 */
final class ReportExports
{
    public const DISK = 'private';

    /** Minutes after the link's expiry before its file is deleted. */
    public const PRUNE_GRACE_MINUTES = 5;

    /** Exports a run looks at, oldest first; the next run takes the rest. */
    public const PRUNE_BATCH = 500;

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

    /**
     * Delete the files of exports whose signed link has expired (the link
     * lives SignedFileLink::TTL_MINUTES and nothing makes a new one), so a
     * report's decrypted contents never sit on disk for good. Each export is
     * marked `file_deleted_at` once its file is gone (deleted now, or already
     * missing); a delete that fails leaves it unmarked for the next run. A
     * run reads only expired exports not yet marked, on the
     * (`file_deleted_at`, `created_at`) index, up to PRUNE_BATCH of them. So
     * no run re-checks old exports, and a scheduler stopped for days still
     * has its files swept when it starts again. The record and checksum stay.
     *
     * @return int files deleted
     */
    public function pruneExpired(): int
    {
        $disk = Storage::disk(self::DISK);
        $deleted = 0;
        $exports = AmlaExport::query()->whereNull('file_deleted_at')
            ->where('created_at', '<', Carbon::now()->subMinutes(SignedFileLink::TTL_MINUTES + self::PRUNE_GRACE_MINUTES))
            ->orderBy('created_at')->orderBy('id')->limit(self::PRUNE_BATCH)->get(['id', 'file_path', 'file_deleted_at']);

        foreach ($exports as $export) {
            if ($disk->exists($export->file_path)) {
                if (! $disk->delete($export->file_path)) {
                    continue;
                }

                $deleted++;
            }

            $export->forceFill(['file_deleted_at' => Carbon::now()])->save();
        }

        return $deleted;
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
