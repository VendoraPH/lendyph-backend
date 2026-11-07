<?php

namespace App\Console\Commands;

use App\Services\RoleMemberDescriptionReword;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('roles:reword-member-descriptions {--dry-run : Show what would be reworded without writing}')]
#[Description('Reword the admin and loan_officer descriptions from "borrowers" to "members" where they still hold the old seeded text')]
class RewordRoleMemberDescriptions extends Command
{
    /**
     * The same logic the 2026_11_07_090000 migration runs on deploy, so a dry
     * run on a database shows what that migration will change there.
     */
    public function handle(RoleMemberDescriptionReword $reword): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $reword->run($dryRun);

        foreach ($result['updated'] as $role) {
            $this->line(sprintf('  %s (id %d): %s', $role['name'], $role['id'], $dryRun ? 'would reword' : 'reworded'));
        }

        foreach ($result['skipped'] as $role) {
            $this->line("  {$role['name']} (id {$role['id']}) left as it is: {$role['reason']}");
        }

        $this->info(sprintf(
            '%s %d role description(s); %d left as edited.',
            $dryRun ? 'Would reword' : 'Reworded',
            count($result['updated']),
            count($result['skipped']),
        ));

        return self::SUCCESS;
    }
}
