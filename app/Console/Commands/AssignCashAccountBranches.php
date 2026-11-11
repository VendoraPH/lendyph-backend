<?php

namespace App\Console\Commands;

use App\Services\Accounting\CashAccountBranchAssignment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('accounting:assign-cash-account-branches {--dry-run : Show which money accounts would get the main branch without writing}')]
#[Description('Give every money account with no branch the main branch, so each branch reconciles its own accounts')]
class AssignCashAccountBranches extends Command
{
    /**
     * The same logic the 2026_11_11_400100 migration runs on deploy, so a dry
     * run on a database shows what it will change there.
     */
    public function handle(CashAccountBranchAssignment $assignment): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $assignment->run($dryRun);
        $branch = $result['branch']['name'] ?? null;

        foreach ($result['assigned'] as $account) {
            $this->line(sprintf('  %s %s: %s %s', $account['code'], $account['name'], $dryRun ? 'would belong to' : 'now belongs to', $branch));
        }

        foreach ($result['skipped'] as $account) {
            $this->line("  {$account['code']} {$account['name']} left with no branch: {$account['reason']}");
        }

        $this->info(sprintf(
            '%s %d money account(s)%s; %d left as they are.',
            $dryRun ? 'Would assign' : 'Assigned',
            count($result['assigned']),
            $branch === null ? '' : " to {$branch}",
            count($result['skipped']),
        ));

        return self::SUCCESS;
    }
}
