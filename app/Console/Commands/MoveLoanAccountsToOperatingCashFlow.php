<?php

namespace App\Console\Commands;

use App\Services\Accounting\CashFlowCategoryBackfill;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('accounting:cash-flow-loans-operating {--dry-run : Show which loan accounts would move without writing}')]
#[Description('Move the loan accounts of a chart seeded before Phase 2 from investing to operating on the cash flow statement, where no user changed them')]
class MoveLoanAccountsToOperatingCashFlow extends Command
{
    /**
     * The same logic the 2026_11_09_110000 migration runs on deploy, so a dry
     * run on a database shows what that migration will change there.
     */
    public function handle(CashFlowCategoryBackfill $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $backfill->run($dryRun);

        if (! $result['chart']) {
            $this->info('No chart of accounts: this organisation keeps no books yet, so there is nothing to move. A new chart is seeded with its loan accounts as operating.');

            return self::SUCCESS;
        }

        foreach ($result['moved'] as $row) {
            $this->line(sprintf('  %s %s %s to operating', $dryRun ? 'Would move' : 'Moved', $row['code'], $row['name']));
        }

        foreach ($result['kept'] as $row) {
            $this->line(sprintf('  %s %s is already operating, left as it is', $row['code'], $row['name']));
        }

        foreach ($result['skipped'] as $row) {
            $this->warn(sprintf('  %s %s skipped: %s.', $row['code'], $row['name'], $row['reason']));
        }

        $this->info(sprintf(
            '%s %d account(s) to operating; %d already operating, %d skipped.',
            $dryRun ? 'Would move' : 'Moved',
            count($result['moved']),
            count($result['kept']),
            count($result['skipped']),
        ));

        return self::SUCCESS;
    }
}
