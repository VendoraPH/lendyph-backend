<?php

namespace App\Console\Commands;

use App\Models\CdaReport;
use App\Services\Cda\Reports\ReportEngine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The nightly CDA refresh (design §4), scheduled at 01:10 Manila in
 * routes/console/cda/revalidate.php: every draft report that applies reads
 * its sources again and is re-validated, so readiness and issues are
 * current each morning. Reports past draft keep their figures. One audit
 * row per report, only when its figures changed, written as the system.
 * A report that fails is logged and the run goes on.
 */
#[Signature('cda:revalidate')]
#[Description('Refresh and re-validate every draft CDA report (sources, calculations, issues and readiness)')]
class RevalidateCdaReports extends Command
{
    public function handle(ReportEngine $engine): int
    {
        $refreshed = 0;
        $changed = 0;
        $failed = 0;

        CdaReport::query()->where('status', CdaReport::STATUS_DRAFT)->where('applicable', true)->orderBy('id')
            ->each(function (CdaReport $report) use ($engine, &$refreshed, &$changed, &$failed): void {
                try {
                    $changes = $engine->refresh($report);
                    $refreshed++;
                    $changed += $changes === [] ? 0 : 1;
                } catch (Throwable $e) {
                    $failed++;
                    Log::warning('CDA report not refreshed.', ['report_id' => $report->id, 'error' => $e->getMessage()]);
                }
            });

        $this->info("CDA reports refreshed: {$refreshed}, with changed figures: {$changed}, failed: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
