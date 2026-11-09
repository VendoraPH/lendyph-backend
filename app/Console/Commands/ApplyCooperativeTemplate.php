<?php

namespace App\Console\Commands;

use App\Services\Accounting\CooperativeTemplateBackfill;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;

#[Signature('accounting:apply-cooperative-template
    {--dry-run : Print every line it would write, and write nothing}
    {--force : Skip the confirmation. Required for a non-interactive run.}')]
#[Description('Add the cooperative chart template (deposits, statutory funds, Undivided Net Surplus) to an existing chart and post net surplus to 3080')]
class ApplyCooperativeTemplate extends Command
{
    use ConfirmableTrait;

    /**
     * For a cooperative already keeping the lending company chart. Never run
     * from a migration: a lending company must not get these accounts.
     */
    public function handle(CooperativeTemplateBackfill $backfill): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $preview = $backfill->run(true);

        if (! $preview['chart']) {
            $this->info('No chart of accounts; nothing to do');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->report($preview, true);

            return self::SUCCESS;
        }

        /*
         * `true`, so it always asks, whatever APP_ENV says (as
         * RecordOpeningBalances does). --force skips it; under
         * --no-interaction the answer is the default, cancel.
         */
        if (! $this->confirmToProceed('Adding the cooperative accounts to this chart', true)) {
            return self::FAILURE;
        }

        $this->report($backfill->run(false), false);

        return self::SUCCESS;
    }

    /**
     * @param  array{chart: bool, created: list<array{code: string, name: string, type: string, parent: string}>, present: list<array{code: string, name: string}>, skipped: list<array{code: string, reason: string}>, mapping: array{status: string, from?: string, to?: string, reason?: string}|null}  $result
     */
    private function report(array $result, bool $dryRun): void
    {
        foreach ($result['created'] as $row) {
            $this->line(sprintf('  %s %s %s (%s, under %s)', $dryRun ? 'Would add' : 'Added', $row['code'], $row['name'], $row['type'], $row['parent']));
        }

        foreach ($result['present'] as $row) {
            $this->line(sprintf('  %s %s is already in the chart, left as it is', $row['code'], $row['name']));
        }

        foreach ($result['skipped'] as $row) {
            $this->warn(sprintf('  %s skipped: %s.', $row['code'], $row['reason']));
        }

        $mapping = $result['mapping'];

        match ($mapping['status'] ?? null) {
            'repointed' => $this->line(sprintf('  %s net_surplus from %s to %s', $dryRun ? 'Would re-point' : 'Re-pointed', $mapping['from'], $mapping['to'])),
            'kept' => $this->line(sprintf('  net_surplus already posts to %s, left as it is', $mapping['to'])),
            default => $this->warn(sprintf('  net_surplus skipped: %s.', $mapping['reason'] ?? 'unknown')),
        };

        $this->info(sprintf(
            '%s %d account(s); net_surplus %s; %d skipped.',
            $dryRun ? 'Would add' : 'Added',
            count($result['created']),
            match ($mapping['status'] ?? null) {
                'repointed' => $dryRun ? 'would be re-pointed' : 're-pointed',
                'kept' => 'already re-pointed',
                default => 'left as it is',
            },
            count($result['skipped']),
        ));
    }
}
