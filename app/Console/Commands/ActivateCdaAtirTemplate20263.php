<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cda:activate-atir-template-2026-3 {--dry-run : List the template change and the drafts that would move, without writing}')]
#[Description('Activate the ATIR template 2026.3, retire ATIR 2026.2 and move the open years\' draft ATIR reports to 2026.3, as the 2026_11_24_200000 migration does')]
class ActivateCdaAtirTemplate20263 extends Command
{
    private const MIGRATION = 'migrations/2026_11_24_200000_activate_cda_atir_template_2026_3.php';

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
        $type = $result['type'];

        if ($type['note'] !== null) {
            $this->line("  atir: left as it is: {$type['note']}.");

            return self::SUCCESS;
        }

        $what = match (true) {
            $type['retired'] && $type['activated'] => $dryRun ? 'would retire 2026.2 and activate 2026.3' : 'retired 2026.2 and activated 2026.3',
            $type['activated'] => $dryRun ? 'would activate 2026.3' : 'activated 2026.3',
            $type['retired'] => $dryRun ? 'would retire 2026.2' : 'retired 2026.2',
            default => '2026.3 already active',
        };

        $this->line(sprintf('  atir: %s; %d draft report(s) %s.', $what, count($result['moved']), $dryRun ? 'would move' : 'moved'));

        return self::SUCCESS;
    }
}
