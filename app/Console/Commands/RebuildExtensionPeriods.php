<?php

namespace App\Console\Commands;

use App\Services\ExtensionPeriodRebuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('loans:rebuild-extension-periods {--dry-run : Show what would be rebuilt without writing}')]
#[Description('Rebuild the partly paid periods extensions deleted, closed at what was collected on them')]
class RebuildExtensionPeriods extends Command
{
    /**
     * The same logic the 2026_10_02_100100 migration runs on every deploy, so
     * a dry run on a database shows what that migration will change there.
     */
    public function handle(ExtensionPeriodRebuilder $rebuilder): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $rebuilder->run($dryRun);

        foreach ($result['rebuilt'] as $loan) {
            foreach ($loan['periods'] as $period) {
                $this->line(sprintf(
                    '  %s period %d due %s: principal %s, interest %s, penalty %s, closed by %s, paid by %s',
                    $loan['loan'],
                    $period['period_number'],
                    $period['due_date'],
                    number_format($period['principal'], 2),
                    number_format($period['interest'], 2),
                    number_format($period['penalty'], 2),
                    $period['closed_by'],
                    implode(', ', $period['payments']),
                ));
            }
        }

        foreach ($result['skipped'] as $loan) {
            $this->line("  {$loan['loan']} left as it is: {$loan['reason']}");
        }

        $periods = collect($result['rebuilt'])->sum(fn (array $loan) => count($loan['periods']));

        $this->info(sprintf(
            '%s %d period(s) on %d loan(s); %d loan(s) with missing payments left as they are.',
            $dryRun ? 'Would rebuild' : 'Rebuilt',
            $periods,
            count($result['rebuilt']),
            count($result['skipped']),
        ));

        return self::SUCCESS;
    }
}
