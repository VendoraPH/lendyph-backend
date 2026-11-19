<?php

namespace App\Console\Commands;

use App\Services\LoanOrigination\Staff\RetentionSweep;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Online applications' expiry and retention (LO-B3; RetentionSweep): drafts
 * unsaved for 24 hours read Incomplete, unsubmitted ones idle for the draft
 * expiration days expire, and expired, rejected and withdrawn applications
 * past their retention are erased, never under a legal hold and never once
 * converted, and old applicant codes and ended applicant sessions (LO-B2's
 * tables) are deleted. Hourly at :25 (routes/console/loan-origination/retention.php).
 * --dry-run lists what it would do and writes nothing.
 */
#[Signature('loan-origination:retention
    {--dry-run : Show what would change without writing anything}')]
#[Description('Mark idle online applications incomplete or expired, and erase those past retention')]
class OriginationRetention extends Command
{
    public function handle(RetentionSweep $sweep): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $sweep->run($dryRun);
        $verb = $dryRun ? 'Would ' : '';

        if ($dryRun) {
            $this->info('Dry run: nothing was written.');
        }

        $this->info(($dryRun ? 'Would mark incomplete' : 'Marked incomplete').': '.count($result['incomplete']));
        $this->info(($dryRun ? 'Would expire' : 'Expired').': '.count($result['expired']));
        $this->info(($dryRun ? 'Would erase' : 'Erased').': '.count($result['erased']));
        $this->info(($dryRun ? 'Would delete old applicant codes' : 'Deleted old applicant codes').': '.$result['codes']);
        $this->info(($dryRun ? 'Would delete ended applicant sessions' : 'Deleted ended applicant sessions').': '.$result['sessions']);

        if ($result['held'] !== []) {
            $this->warn('Kept under a legal hold: '.$this->ids($result['held']));
        }

        if ($result['skipped'] !== []) {
            $this->warn($verb.'Skipped, changed while the sweep ran (next run picks them up): '.$this->ids($result['skipped']));
        }

        return self::SUCCESS;
    }

    /** @param  list<int>  $ids */
    private function ids(array $ids): string
    {
        return implode(', ', array_map(fn (int $id) => "#{$id}", $ids));
    }
}
