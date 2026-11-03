<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Services\Notifications;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tells each past-due loan's assigned officer, once per past-due episode, that
 * the loan has fallen behind (decision N, Arvin, 2026-11-02). Scheduled daily
 * at 00:20 Asia/Manila in routes/console.php, after loans:check-defaulted and
 * loans:apply-penalties.
 *
 * Past due is Loan::scopePastDue(): a collectible loan (released, ongoing,
 * defaulted) with an unpaid period past its due date plus the loan's grace,
 * the same rule as the loans list's Past Due tab and the dashboard's overdue
 * count.
 *
 * Once per episode: a loan announced gets a `loan_past_due_notices` row and is
 * not announced again while it stays past due. Each run first deletes the rows
 * of loans no longer past due (paid up, closed, restructured), so a loan that
 * falls behind again later is a new episode and is announced again. Safe to
 * re-run on the same day.
 */
#[Signature('notifications:past-due')]
#[Description('Notify assigned officers of loans that have just passed their grace period (once per past-due episode)')]
class NotifyPastDueLoans extends Command
{
    private const TABLE = 'loan_past_due_notices';

    public function handle(Notifications $notifications): int
    {
        $today = Carbon::today()->toDateString();

        $closed = DB::table(self::TABLE)
            ->whereNotIn('loan_id', Loan::query()->pastDue()->select('loans.id'))
            ->delete();

        $announced = 0;

        Loan::query()
            ->pastDue()
            ->whereNotExists(fn ($marker) => $marker->from(self::TABLE)->whereColumn(self::TABLE.'.loan_id', 'loans.id'))
            ->with('borrower')
            ->withMin(['amortizationSchedules as oldest_late_due_date' => fn ($schedules) => $schedules
                ->whereIn('amortization_schedules.status', AmortizationSchedule::UNPAID_STATUSES)
                ->whereRaw(AmortizationSchedule::pastGraceSql(), [$today]),
            ], 'due_date')
            ->chunkById(200, function (Collection $loans) use ($notifications, &$announced): void {
                foreach ($loans as $loan) {
                    // The marker and the notification commit together; the
                    // unique loan_id makes a concurrent run insert nothing and
                    // so notify nobody twice.
                    DB::transaction(function () use ($loan, $notifications, &$announced): void {
                        $inserted = DB::table(self::TABLE)->insertOrIgnore([
                            'loan_id' => $loan->id,
                            'notified_at' => now(),
                        ]);

                        if ($inserted === 0) {
                            return;
                        }

                        $notifications->loanPastDue($loan, $this->dateLabel($loan->oldest_late_due_date));
                        $announced++;
                    });
                }
            }, 'loans.id', 'id');

        $this->info("Past-due loans announced: {$announced}. Episodes closed: {$closed}.");

        return self::SUCCESS;
    }

    private function dateLabel(mixed $date): ?string
    {
        return $date === null ? null : Carbon::parse((string) $date)->format('M j, Y');
    }
}
