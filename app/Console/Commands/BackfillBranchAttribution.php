<?php

namespace App\Console\Commands;

use App\Services\BranchAttributionBackfill;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('branches:backfill-attribution {--dry-run : Show what would be set without writing}')]
#[Description('Give past share capital entries and member GCash transactions the branch their posted journal is in')]
class BackfillBranchAttribution extends Command
{
    /**
     * The same logic the 2026_11_19_300100 migration runs on deploy, so a dry
     * run on a database shows what that migration will change there.
     */
    public function handle(BranchAttributionBackfill $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $backfill->run($dryRun);

        foreach (['share_capital' => 'Share capital entry', 'gcash' => 'GCash transaction'] as $kind => $label) {
            foreach ($result[$kind]['updated'] as $row) {
                $this->line("  {$label} {$row['reference']} (id {$row['id']}): branch #{$row['branch_id']}, from journal ".($row['journal_no'] ?? 'without a number'));
            }

            foreach ($result[$kind]['skipped'] as $row) {
                $this->line("  {$label} {$row['reference']} (id {$row['id']}) left as it is: {$row['reason']}");
            }
        }

        $this->info(sprintf(
            '%s the branch on %d share capital entr%s and %d member GCash transaction(s); %d and %d left as they are.',
            $dryRun ? 'Would set' : 'Set',
            count($result['share_capital']['updated']),
            count($result['share_capital']['updated']) === 1 ? 'y' : 'ies',
            count($result['gcash']['updated']),
            count($result['share_capital']['skipped']),
            count($result['gcash']['skipped']),
        ));

        return self::SUCCESS;
    }
}
