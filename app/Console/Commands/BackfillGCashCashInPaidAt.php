<?php

namespace App\Console\Commands;

use App\Services\GCashCashInPaidAtBackfill;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('gcash:backfill-cash-in-paid-at {--dry-run : Show what would be set without writing}')]
#[Description('Set paid_at on Cash In transactions recorded as paid without one, to when they were recorded')]
class BackfillGCashCashInPaidAt extends Command
{
    /**
     * The same logic the 2026_10_03_120000 migration runs on deploy, so a dry
     * run on a database shows what that migration will change there.
     */
    public function handle(GCashCashInPaidAtBackfill $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $backfill->run($dryRun);

        foreach ($result['updated'] as $row) {
            $this->line("  {$row['reference_no']} (id {$row['id']}): paid_at {$row['paid_at']}");
        }

        foreach ($result['skipped'] as $row) {
            $this->line("  {$row['reference_no']} (id {$row['id']}) left as it is: {$row['reason']}");
        }

        $this->info(sprintf(
            '%s paid_at on %d Cash In transaction(s)%s; %d skipped%s.',
            $dryRun ? 'Would set' : 'Set',
            count($result['updated']),
            $this->ids($result['updated']),
            count($result['skipped']),
            $this->ids($result['skipped']),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<array{id: int}>  $rows
     */
    private function ids(array $rows): string
    {
        return $rows === [] ? '' : ' (ids '.implode(', ', array_column($rows, 'id')).')';
    }
}
