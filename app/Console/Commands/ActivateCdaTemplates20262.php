<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cda:activate-templates-2026-2 {--dry-run : List the templates that would change and the drafts that would move, without writing}')]
#[Description('Activate the CDA templates 2026.2, retire 2026.1 and move the open years\' draft reports to 2026.2, as the 2026_11_13_130000 migration does')]
class ActivateCdaTemplates20262 extends Command
{
    private const MIGRATION = 'migrations/2026_11_13_130000_activate_cda_templates_2026_2.php';

    /**
     * Runs the migration's own run(), so a dry run on a database shows what
     * the migration will change there.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run. Nothing is written.');
        }

        $migration = require database_path(self::MIGRATION);
        $result = $migration->run($dryRun);
        $moved = collect($result['moved'])->countBy('type');

        foreach ($result['types'] as $type) {
            $drafts = (int) ($moved[$type['type']] ?? 0);

            if ($type['note'] !== null) {
                $this->line("  {$type['type']}: left as it is: {$type['note']}.");

                continue;
            }

            $what = match (true) {
                $type['retired'] && $type['activated'] => $dryRun ? 'would retire 2026.1 and activate 2026.2' : 'retired 2026.1 and activated 2026.2',
                $type['activated'] => $dryRun ? 'would activate 2026.2' : 'activated 2026.2',
                $type['retired'] => $dryRun ? 'would retire 2026.1' : 'retired 2026.1',
                default => '2026.2 already active',
            };

            $this->line(sprintf('  %s: %s; %d draft report(s) %s.', $type['type'], $what, $drafts, $dryRun ? 'would move' : 'moved'));
        }

        $this->info(sprintf('%s %d draft report(s) to 2026.2.', $dryRun ? 'Would move' : 'Moved', count($result['moved'])));

        return self::SUCCESS;
    }
}
