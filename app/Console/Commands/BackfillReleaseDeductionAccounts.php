<?php

namespace App\Console\Commands;

use App\Services\Accounting\ReleaseDeductionAccountsBackfill;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('accounting:backfill-release-deduction-accounts {--dry-run : Show what would be added and mapped without writing}')]
#[Description('Add the release deduction accounts and posting roles (accountant-confirmed 2026-10-03) to a chart seeded before them')]
class BackfillReleaseDeductionAccounts extends Command
{
    /**
     * The same logic the 2026_10_03_130000 migration runs on deploy, so a dry
     * run on a database shows what that migration will change there.
     */
    public function handle(ReleaseDeductionAccountsBackfill $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $backfill->run($dryRun);

        if (! $result['chart']) {
            $this->info('No chart of accounts: this organisation keeps no books yet, so there is nothing to add. Seeding a chart adds these accounts and roles.');

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

        $this->info(sprintf(
            '%s %d account(s) and %s %d role(s); %d skipped.',
            $dryRun ? 'Would add' : 'Added',
            count($result['created']),
            $dryRun ? 'map' : 'mapped',
            count($result['mapped']),
            count($result['skipped']),
        ));

        return self::SUCCESS;
    }
}
