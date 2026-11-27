<?php

namespace App\Console\Commands;

use App\Services\BranchAttributionBackfill;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('branches:attribution-report')]
#[Description('List share capital entries and member GCash transactions no posted journal attributes to a branch, and members with no branch (read-only)')]
class BranchAttributionReport extends Command
{
    /**
     * Read-only: a dry run of the backfill, keeping what it would skip. Those
     * rows count in their member's current branch until a journal proves
     * otherwise; members with no branch count in none.
     */
    public function handle(BranchAttributionBackfill $backfill): int
    {
        $result = $backfill->run(true);
        $pending = count($result['share_capital']['updated']) + count($result['gcash']['updated']);

        if ($pending > 0) {
            $this->warn("{$pending} row(s) can be attributed from their posted journal: run branches:backfill-attribution.");
        }

        foreach (['share_capital' => 'Share capital entries', 'gcash' => 'Member GCash transactions'] as $kind => $label) {
            $skipped = $result[$kind]['skipped'];
            $this->info(sprintf('%s with no branch a journal proves: %d', $label, count($skipped)));

            foreach ($skipped as $row) {
                $this->line("  {$row['reference']} (id {$row['id']}): {$row['reason']}");
            }
        }

        $members = $backfill->membersWithNoBranch();
        $this->info(sprintf('Members with no branch: %d', count($members)));

        foreach ($members as $member) {
            $this->line("  {$member['borrower_code']} (id {$member['id']}, {$member['status']})");
        }

        return self::SUCCESS;
    }
}
