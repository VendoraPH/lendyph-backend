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

    /** How far back the regular sweep looks: a day of 15-minute runs, so a missed run or a short outage still sweeps its files. */
    public const PRUNE_LOOKBACK_HOURS = 24;

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
     * report's decrypted contents never sit on disk for good. The export's
     * record and checksum stay.
     *
     * The regular sweep looks only at exports that expired in the last
     * PRUNE_LOOKBACK_HOURS (a range on the indexed `created_at`), so it never
     * re-checks every old export. `$full` checks every expired export, for a
     * sweep after a longer outage (`amla:prune-exports --full`).
     *
     * @return int files deleted
     */
    public function pruneExpired(bool $full = false): int
    {
        $disk = Storage::disk(self::DISK);
        $deleted = 0;
        $expiredBefore = Carbon::now()->subMinutes(SignedFileLink::TTL_MINUTES + self::PRUNE_GRACE_MINUTES);
        $paths = AmlaExport::query()->where('created_at', '<', $expiredBefore)
            ->when(! $full, fn ($query) => $query->where('created_at', '>=', $expiredBefore->copy()->subHours(self::PRUNE_LOOKBACK_HOURS)))
            ->orderBy('id')->pluck('file_path');

        foreach ($paths as $path) {
            if ($disk->exists($path)) {
                $disk->delete($path);
                $deleted++;
            }
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
