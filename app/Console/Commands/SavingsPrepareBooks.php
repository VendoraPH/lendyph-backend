<?php

namespace App\Console\Commands;

use App\Services\Savings\SavingsBooks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('savings:prepare-books {--dry-run : Show what would be added and mapped without writing}')]
#[Description('Turn savings on for this chart of accounts: add any missing 2050 Savings Deposits, 2060 Time Deposits and 5160 Interest Expense on Deposits, and map the four savings posting roles')]
class SavingsPrepareBooks extends Command
{
    /**
     * Idempotent and audited (SavingsBooks::prepare()). The 2026_11_10_500200
     * migration does the same on deploy for a chart that already has 2050.
     */
    public function handle(SavingsBooks $books): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $books->prepare($dryRun);

        if (! $result['chart']) {
            $this->info('There is no chart of accounts: this organisation keeps no books yet, so there is nothing to add. ('.SavingsBooks::summary($result).')');

            return self::SUCCESS;
        }

        foreach ($result['created'] as $row) {
            $this->line(sprintf('  %s %s %s (%s)', $dryRun ? 'Would add' : 'Added', $row['code'], $row['name'], $row['type']));
        }

        foreach ($result['mapped'] as $row) {
            $this->line(sprintf('  %s %s to %s %s', $dryRun ? 'Would map' : 'Mapped', $row['role'], $row['code'], $row['name']));
        }

        foreach ($result['kept'] as $row) {
            $this->line(sprintf('  %s is already mapped (to %s), left as it is', $row['role'], $row['code'] ?? 'an account that no longer exists'));
        }

        foreach ($result['skipped'] as $row) {
            $this->warn(sprintf('  %s skipped: %s. Set it in Accounting → Settings → Default Accounts.', $row['role'], $row['reason']));
        }

        $this->info(SavingsBooks::summary($result).'.');

        if ($dryRun) {
            $this->comment('Dry run: nothing was written.');
        }

        return self::SUCCESS;
    }
}
