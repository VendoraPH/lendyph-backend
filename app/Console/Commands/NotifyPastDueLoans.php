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
use Illuminate\Support\Facades\Log;
use Throwable;

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
 *
 * Each loan's marker and notification are written in one transaction, and
 * the notification is sent inside it rather than after commit, so a failed
 * send rolls the marker back. The failure is logged and the run moves on to
 * the next loan; the next run tries that loan again.
 */
#[Signature('notifications:past-due')]
#[Description('Notify assigned officers of loans that have just passed their grace period (once per past-due episode)')]
class NotifyPastDueLoans extends Command
{
    private const TABLE = 'loan_past_due_notices';

    public function handle(Notifications $notifications): int
    {
        $today = Carbon::today()->toDateString();

        // Read first, then delete by key: a DELETE with the past-due subquery
        // inside it would hold shared locks on the loans and their schedules
        // for the length of the delete.
        $pastDueIds = Loan::query()->pastDue()->pluck('loans.id')->map(fn ($id) => (int) $id)->all();
        $staleLoanIds = DB::table(self::TABLE)->pluck('loan_id')->map(fn ($id) => (int) $id)->diff($pastDueIds)->values()->all();
        $closed = $staleLoanIds === [] ? 0 : DB::table(self::TABLE)->whereIn('loan_id', $staleLoanIds)->delete();

        $announced = 0;
        $failed = 0;

        Loan::query()
            ->pastDue()
            ->whereNotExists(fn ($marker) => $marker->from(self::TABLE)->whereColumn(self::TABLE.'.loan_id', 'loans.id'))
            ->with('borrower')
            ->withMin(['amortizationSchedules as oldest_late_due_date' => fn ($schedules) => $schedules
                ->whereIn('amortization_schedules.status', AmortizationSchedule::UNPAID_STATUSES)
                ->whereRaw(AmortizationSchedule::pastGraceSql(), [$today]),
            ], 'due_date')
            ->chunkById(200, function (Collection $loans) use ($notifications, &$announced, &$failed): void {
                foreach ($loans as $loan) {
                    try {
                        // The marker and the notification commit together, or
                        // neither does; the unique loan_id makes a concurrent
                        // run insert nothing and so notify nobody twice.
                        $sent = DB::transaction(function () use ($loan, $notifications): bool {
                            $inserted = DB::table(self::TABLE)->insertOrIgnore([
                                'loan_id' => $loan->id,
                                'notified_at' => now(),
                            ]);

                            if ($inserted === 0) {
                                return false;
                            }

                            $notifications->loanPastDue($loan, $this->dateLabel($loan->oldest_late_due_date));

                            return true;
                        });

                        $announced += $sent ? 1 : 0;
                    } catch (Throwable $e) {
                        $failed++;
                        Log::error('notifications:past-due could not notify a loan; it will be retried on the next run.', [
                            'loan_id' => $loan->id,
                            'exception' => $e,
                        ]);
                    }
                }
            }, 'loans.id', 'id');

        $this->info("Past-due loans announced: {$announced}. Episodes closed: {$closed}. Failed (retried next run): {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function dateLabel(mixed $date): ?string
    {
        return $date === null ? null : Carbon::parse((string) $date)->format('M j, Y');
    }
}
