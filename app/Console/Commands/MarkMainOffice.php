<?php

namespace App\Console\Commands;

use App\Services\Branches\MainOfficeMarker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('branches:mark-main-office {--dry-run : Show which branch would be marked without writing}')]
#[Description('Mark the main office: the one branch today\'s main-branch rule finds (Phase 6 data fix 1)')]
class MarkMainOffice extends Command
{
    /**
     * The same fix the 2026_11_19_310200 migration runs on deploy, so a dry
     * run shows what it will change on a database. Exits with failure when
     * there is no clear main branch, listing the active branches.
     */
    public function handle(MainOfficeMarker $marker): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run. Nothing is written.');
        }

        $result = $marker->run($dryRun);

        if ($result['already'] !== null) {
            $this->info("{$result['already']} is already the main office. Nothing to do.");

            return self::SUCCESS;
        }

        $branch = $result['branch'];

        if ($branch === null) {
            $this->warn("No main office: {$result['reason']}.");

            foreach ($result['active'] as $active) {
                $this->line("  {$active['name']} ({$active['code']}, id {$active['id']})");
            }

            if ($result['active'] === []) {
                return self::SUCCESS;
            }

            $this->error('Nothing was marked: make one branch the main office in Settings > Branches.');

            return self::FAILURE;
        }

        $this->line("Main office: {$branch['name']} ({$branch['code']}, id {$branch['id']}), {$branch['basis']}.");

        if ($dryRun) {
            $this->info("Would mark {$branch['name']} as the main office.");

            return self::SUCCESS;
        }

        if (! $result['marked']) {
            $this->warn("Nothing was marked: {$result['reason']}. Run this again.");

            return self::FAILURE;
        }

        $this->info("Marked {$branch['name']} as the main office.");

        return self::SUCCESS;
    }
}
