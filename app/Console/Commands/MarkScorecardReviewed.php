<?php

namespace App\Console\Commands;

use App\Services\ScorecardOwnerReview;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('credit-scoring:mark-scorecard-reviewed {--dry-run : Show whether scorecard 1.0 would be marked reviewed without writing}')]
#[Description('Record that the owner reviewed scorecard 1.0 on 2026-11-11 and kept its weights and risk levels, so it is no longer reported as unvalidated')]
class MarkScorecardReviewed extends Command
{
    /**
     * The same logic the 2026_11_11_620200 migration runs on deploy, so a dry
     * run on a database shows what it will change there.
     */
    public function handle(ScorecardOwnerReview $review): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run. Nothing is written.');
        }

        $result = $review->run($dryRun);

        foreach ($result['marked'] as $revision) {
            $this->line(sprintf(
                '  Scorecard %s (id %d, %s): %s by the owner on %s',
                $revision['version'],
                $revision['id'],
                $revision['in_use'] ? 'in use' : 'not in use',
                $dryRun ? 'would be marked reviewed' : 'marked reviewed',
                ScorecardOwnerReview::REVIEWED_ON,
            ));
        }

        foreach ($result['skipped'] as $revision) {
            $this->line("  Scorecard {$revision['version']} (id {$revision['id']}) left as it is: {$revision['reason']}");
        }

        $this->info(sprintf(
            '%s %d scorecard revision(s) reviewed; %d left as they are.',
            $dryRun ? 'Would mark' : 'Marked',
            count($result['marked']),
            count($result['skipped']),
        ));

        return self::SUCCESS;
    }
}
