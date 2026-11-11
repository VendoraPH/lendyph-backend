<?php

namespace App\Console\Commands;

use App\Services\DefaultBranchAssignment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('users:assign-default-branch {--dry-run : Show who would join the main branch without writing}')]
#[Description('Put active staff with no branch (and no branches:view_all) in the main branch; admins and super_admins are left alone')]
class AssignDefaultBranch extends Command
{
    /**
     * The same logic the 2026_11_11_620000 migration runs on deploy, so a dry
     * run on a database shows what it will change there. Exits with failure
     * when there is no clear main branch and users were left unassigned.
     */
    public function handle(DefaultBranchAssignment $assignment): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run. Nothing is written.');
        }

        $result = $assignment->run($dryRun);
        $branch = $result['branch'];

        if ($branch === null) {
            $this->warn("No main branch: {$result['no_branch_reason']}.");
        } else {
            $this->line("Main branch: {$branch['name']} ({$branch['code']}, id {$branch['id']}), {$branch['basis']}.");
        }

        foreach ($result['assigned'] as $user) {
            $this->line(sprintf('  %s (id %d): %s %s', $user['username'], $user['id'], $dryRun ? 'would join' : 'joined', $branch['name'] ?? ''));
        }

        foreach ($result['skipped'] as $user) {
            $this->line("  {$user['username']} (id {$user['id']}) skipped: {$user['reason']}");
        }

        $this->info(sprintf(
            '%s %d user(s) to the main branch; %d skipped.',
            $dryRun ? 'Would assign' : 'Assigned',
            count($result['assigned']),
            count($result['skipped']),
        ));

        if ($branch === null && $result['skipped'] !== []) {
            $this->error('Nobody was assigned: name the main branch "Main Branch" or "Head Office", or assign these users in Settings > Users, then run this again.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
