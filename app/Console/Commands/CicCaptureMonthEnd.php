<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CicCapture;
use App\Services\Cic\Capture\CicCaptureService;
use App\Services\Cic\CicSwitch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The CIC month-end capture (plan decision 2): at 23:58 Manila on each
 * month's last day, every reportable contract's figures and its subjects'
 * data are captured and frozen with a checksum. Files and corrections are
 * built only from these captures, and a month that wasn't captured can't be
 * rebuilt later.
 *
 * - Does nothing while CIC is off (`CIC_ENABLED`).
 * - Idempotent: a date that already has a complete month-end capture is
 *   skipped, so a re-run never takes a second one.
 * - `--date=` captures a given month's last day (tests, or a missed run on
 *   the next morning). It must be a month's last day and not in the future;
 *   the figures are today's rows either way, and the command says so.
 */
#[Signature('cic:capture-month-end
    {--date= : The month end to capture (Y-m-d, a month\'s last day); default today in Manila}')]
#[Description('Capture and freeze the CIC records at a month end')]
class CicCaptureMonthEnd extends Command
{
    public function handle(CicSwitch $switch, CicCaptureService $captures): int
    {
        if (! $switch->enabled()) {
            $this->info('CIC reporting is off; nothing captured.');

            return self::SUCCESS;
        }

        $today = Carbon::today();
        $option = $this->option('date');

        if ($option !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $option) !== 1) {
            $this->error('--date must be a date written Y-m-d.');

            return self::FAILURE;
        }

        $date = $option === null ? $today : Carbon::createFromFormat('!Y-m-d', (string) $option);

        if ($date === false || $date->toDateString() !== $date->copy()->endOfMonth()->toDateString()) {
            $this->error('A month-end capture is dated on a month\'s last day.');

            return self::FAILURE;
        }

        if ($date->gt($today)) {
            $this->error('A month end that hasn\'t come yet can\'t be captured.');

            return self::FAILURE;
        }

        if ($captures->completeMonthEnd($date) !== null) {
            $this->info("{$date->toDateString()} already has a complete month-end capture; left as it is.");

            return self::SUCCESS;
        }

        if ($date->lt($today)) {
            $this->warn("Capturing {$date->toDateString()} with today's figures: every date is compared with that month end, but balances are as they stand now.");
        }

        $capture = $captures->capture($date, CicCapture::KIND_MONTH_END, null);

        if (! $capture->isComplete()) {
            $this->error("CIC month-end capture #{$capture->id} failed: {$capture->error}");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'CIC month-end capture #%d for %s: %d subjects, %d contracts, %d events.',
            $capture->id,
            $date->toDateString(),
            $capture->subject_count,
            $capture->contract_count,
            $capture->event_count,
        ));

        return self::SUCCESS;
    }
}
