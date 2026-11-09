<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CreditScoreDirtyMember;
use App\Services\CreditScoring\ChangeScanner;
use App\Services\CreditScoring\Recalculator;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The credit scoring minute job (every minute, in the background,
 * routes/console/credit-scoring.php): flags the members whose payments,
 * loans, schedules, details or application details changed since the last
 * scan (ChangeScanner), then rescores the flagged members, oldest flag first,
 * with their open loan assessments.
 *
 * Each tick stops after 45 seconds or 200 members, whichever comes first, so
 * it returns before the next tick; what's left waits for the next one. A
 * member flagged again while being scored stays flagged. A member whose
 * scoring fails is logged by id, counted, and moved to the back of the queue,
 * and the tick carries on; the command then exits with a failure. Advisory
 * only: no loan is ever written.
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
        $failed = 0;

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

            try {
                if ($recalculator->isScorable($borrowerId)) {
                    $recalculator->scoreMember($borrowerId, $dirty->reason);

                    foreach ($recalculator->openAssessedLoans($borrowerId) as $loan) {
                        $recalculator->assessLoan($loan, $dirty->reason);
                    }

                    $scored++;
                }
            } catch (Throwable) {
                $this->sendToBack($dirty);
                $failed++;

                continue;
            }

            $recalculator->clearDirty($borrowerId, $dirty->flagged_at);
        }

        $this->info("Flagged {$flagged} member(s); rescored {$scored}".($failed > 0 ? ", {$failed} failed and moved to the back of the queue." : '.'));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Count the failure and requeue the member behind everyone flagged so
     * far, so a member who keeps failing never holds up the others. Only the
     * member's id is logged: an error's text can carry personal data.
     */
    private function sendToBack(CreditScoreDirtyMember $dirty): void
    {
        CreditScoreDirtyMember::query()->whereKey($dirty->id)->update([
            'attempts' => $dirty->attempts + 1,
            'flagged_at' => Carbon::now(),
        ]);

        Log::warning('Credit scoring failed for a member.', ['borrower_id' => (int) $dirty->borrower_id, 'attempts' => $dirty->attempts + 1]);
    }
}
