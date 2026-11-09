<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CreditScoreDirtyMember;
use App\Services\CreditScoring\ChangeScanner;
use App\Services\CreditScoring\Recalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The credit scoring minute job (every minute, in the background,
 * routes/console/credit-scoring.php): flags the members whose payments,
 * loans, schedules, details or application details changed since the last
 * scan (ChangeScanner), then rescores the flagged members, oldest flag first,
 * with their open loan assessments.
 *
 * Each tick stops after 45 seconds or 200 members, whichever comes first, so
 * it returns before the next tick; what's left waits for the next one. A
 * member flagged again while being scored stays flagged. Advisory only: no
 * loan is ever written.
 */
#[Signature('credit-scoring:process-changes')]
#[Description('Rescore members whose data changed since the last scan (advisory only)')]
class ProcessCreditScoreChanges extends Command
{
    public const MAX_SECONDS = 45;

    public const MAX_MEMBERS = 200;

    public function handle(ChangeScanner $scanner, Recalculator $recalculator): int
    {
        $started = microtime(true);
        $flagged = $scanner->scan();
        $scored = 0;

        $pending = CreditScoreDirtyMember::query()
            ->orderBy('flagged_at')
            ->orderBy('id')
            ->limit(self::MAX_MEMBERS)
            ->get();

        foreach ($pending as $dirty) {
            if (microtime(true) - $started >= self::MAX_SECONDS) {
                break;
            }

            $borrowerId = (int) $dirty->borrower_id;

            if ($recalculator->isScorable($borrowerId)) {
                $recalculator->scoreMember($borrowerId, $dirty->reason);

                foreach ($recalculator->openAssessedLoans($borrowerId) as $loan) {
                    $recalculator->assessLoan($loan, $dirty->reason);
                }

                $scored++;
            }

            $recalculator->clearDirty($borrowerId, $dirty->flagged_at);
        }

        $this->info("Flagged {$flagged} member(s); rescored {$scored}.");

        return self::SUCCESS;
    }
}
