<?php

namespace App\Console\Commands;

use App\Services\Cda\YearEndCapture;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Scheduled at 23:58 Manila on Dec 31 (routes/console/cda/capture.php),
 * after `portfolio:snapshot` at 23:55. Safe to run twice: the first capture
 * stands. `--date` runs it by hand for a past Dec 31 (the live balances are
 * still today's, so only on the day itself is it exact).
 */
#[Signature('cda:capture-year-end {--date= : The Dec 31 to capture (YYYY-MM-DD); today in Manila by default}')]
#[Description('Keep the year-end loan aging, required allowance and 1200 balances on the CDA reporting period')]
class CaptureCdaYearEnd extends Command
{
    public function handle(YearEndCapture $capture): int
    {
        $date = (string) ($this->option('date') ?: now()->timezone('Asia/Manila')->toDateString());

        try {
            $result = $capture->capture($date);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $period = $result['period'];

        if (! $result['captured']) {
            $this->info("{$period->year} was already captured at {$period->year_end_captured_at?->toDateTimeString()}; nothing changed.");

            return self::SUCCESS;
        }

        $totals = $period->year_end_capture['totals'];
        $this->info("Captured {$period->year}: {$totals['loan_count']} loans as of {$date}.");

        return self::SUCCESS;
    }
}
