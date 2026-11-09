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
 * member flagged again while being scored stays flagged.
 *
 * A member whose scoring fails is logged by id and counted, and the tick
 * carries on; the command then exits with a failure. The member backs off:
 * `flagged_at` moves to now plus 2^attempts minutes (at most 60), and a tick
 * reads only flags whose `flagged_at` has come, so failing members never
 * crowd out a new change. A new change while a member is backed off keeps
 * their retry time (ChangeScanner::flag() keeps the later `flagged_at`), and
 * the attempts still count. After MAX_ATTEMPTS failures the minute job leaves
 * the member to the nightly run, which clears the flag once it scores them.
 * Advisory only: no loan is ever written.
 */
#[Signature('credit-scoring:process-changes')]
#[Description('Rescore members whose data changed since the last scan (advisory only)')]
class ProcessCreditScoreChanges extends Command
{
    public const MAX_SECONDS = 45;

    public const MAX_MEMBERS = 200;

    public const MAX_ATTEMPTS = 5;

    public const MAX_RETRY_DELAY_MINUTES = 60;

    public function handle(ChangeScanner $scanner, Recalculator $recalculator): int
    {
        $started = microtime(true);
        $flagged = $scanner->scan();
        $scored = 0;
        $failed = 0;

        $pending = CreditScoreDirtyMember::query()
            ->where('flagged_at', '<=', Carbon::now())
            ->where('attempts', '<', self::MAX_ATTEMPTS)
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
                $this->backOff($dirty);
                $failed++;

                continue;
            }

            $recalculator->clearDirty($borrowerId, $dirty->flagged_at);
        }

        $this->info("Flagged {$flagged} member(s); rescored {$scored}".($failed > 0 ? ", {$failed} failed and will be retried later." : '.'));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The wait before a member's next try after `$attempts` failures:
     * 2^attempts minutes, at most MAX_RETRY_DELAY_MINUTES.
     */
    public static function retryDelayMinutes(int $attempts): int
    {
        return min(2 ** min(max($attempts, 0), 30), self::MAX_RETRY_DELAY_MINUTES);
    }

    /**
     * Count the failure and hold the member back until their retry time, so
     * a member who keeps failing never holds up the others. Only the
     * member's id is logged: an error's text can carry personal data.
     */
    private function backOff(CreditScoreDirtyMember $dirty): void
    {
        $attempts = $dirty->attempts + 1;

        CreditScoreDirtyMember::query()->whereKey($dirty->id)->update([
            'attempts' => $attempts,
            'flagged_at' => Carbon::now()->addMinutes(self::retryDelayMinutes($attempts)),
        ]);

        Log::warning('Credit scoring failed for a member.', ['borrower_id' => (int) $dirty->borrower_id, 'attempts' => $attempts]);
    }
}
