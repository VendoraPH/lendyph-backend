<?php

namespace App\Console\Commands;

use App\Services\Savings\Reports\GlControl;
use Illuminate\Console\Command;

/**
 * The nightly savings GL control (design §6): the savings subledger against
 * GL 2050 and 2060, per branch, as of today. A difference other than ₱0.00
 * tells the active `savings:post` holders who can see that branch, in the
 * app, at most once per day each. Read-only otherwise; nothing when there
 * are no savings records.
 */
class SavingsGlControl extends Command
{
    protected $signature = 'savings:gl-control';

    protected $description = 'Compare the savings subledger with the general ledger by branch, and tell savings:post holders about a difference';

    public function handle(GlControl $control): int
    {
        if (! $control->hasSavings()) {
            $this->info('No savings records; nothing to check.');

            return self::SUCCESS;
        }

        $result = $control->build(now()->toDateString(), null);

        if ($result['is_balanced']) {
            $this->info("Savings agree with the general ledger as of {$result['as_of']}.");

            return self::SUCCESS;
        }

        $told = $control->notify($result);
        $this->warn("{$result['differences']} savings balance(s) differ from the general ledger as of {$result['as_of']}: total difference ₱{$result['totals']['difference']}. {$told} user(s) told.");

        return self::SUCCESS;
    }
}
