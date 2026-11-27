<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Record which periods each existing posted payment paid, where that can be
 * proved, so voiding it reverses those periods and no others.
 *
 * Until `repayment_allocations` existed a payment stored only its totals. The
 * periods come from the order payments are applied in: RepaymentService takes
 * open periods strictly by period number and, within a period, penalty, then
 * interest, then principal, settling it before moving on. So across a loan's
 * payments, in the order they were recorded, each component (penalty, interest,
 * principal) fills the periods front to back. Replaying each payment's stored
 * totals that way against the periods' due amounts reproduces where it went.
 * Penalty fills each period up to what it now holds as paid, because the
 * charge itself is rewritten by every penalty run.
 *
 * Rows are written for a loan only when the replay matches exactly: every
 * payment's totals placed in full, and every period's principal, interest and
 * penalty paid equal to what the replay put there. Anything that rewrote the
 * periods behind the payments' backs (an extension or restructure deleting a
 * partly paid period, the old waiver zeroing paid penalty) breaks that match,
 * and the loan is left without rows and listed in the log. A loan with a voided
 * payment or closed by a restructure is not replayed at all: the old void took
 * from whichever periods came first, and a restructured loan refuses voids
 * anyway. Payments left without rows cannot be voided (see
 * RepaymentService::assertAllocationIsKnown()).
 *
 * Only loans where no posted payment has rows yet are considered, so rows
 * already written, by this or by a payment since, are never touched and
 * running it again changes nothing.
 */
return new class extends Migration
{
    /** Component => [repayment total column, period paid column, period capacity column]. */
    private const COMPONENTS = [
        'penalty' => ['penalty_applied', 'penalty_paid', 'penalty_paid'],
        'interest' => ['interest_applied', 'interest_paid', 'interest_due'],
        'principal' => ['principal_applied', 'principal_paid', 'principal_due'],
    ];

    public function up(): void
    {
        $loanIds = DB::table('repayments')
            ->where('status', 'posted')
            ->whereRaw('principal_applied + interest_applied + penalty_applied > 0')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('repayment_allocations')
                ->whereColumn('repayment_allocations.repayment_id', 'repayments.id'))
            ->distinct()
            ->orderBy('loan_id')
            ->pluck('loan_id');

        $recorded = ['loans' => 0, 'payments' => 0];
        $skipped = [];

        foreach ($loanIds as $loanId) {
            $outcome = $this->replay((int) $loanId);

            if (is_string($outcome)) {
                $skipped[] = ['loan_id' => (int) $loanId, 'reason' => $outcome];

                continue;
            }

            DB::transaction(fn () => DB::table('repayment_allocations')->insert($outcome));
            $recorded['loans']++;
            $recorded['payments'] += collect($outcome)->pluck('repayment_id')->unique()->count();
        }

        Log::info(sprintf(
            'Backfilled repayment allocations: %d payment(s) on %d loan(s) recorded, %d loan(s) left without rows.',
            $recorded['payments'],
            $recorded['loans'],
            count($skipped),
        ), ['skipped' => $skipped]);
    }

    /**
     * The allocation rows for one loan's posted payments, or why there are none.
     *
     * @return list<array<string, mixed>>|string
     */
    private function replay(int $loanId): array|string
    {
        if (DB::table('loans')->where('id', $loanId)->value('status') === 'restructured') {
            return 'closed by a restructure';
        }

        if (DB::table('repayments')->where('loan_id', $loanId)->where('status', 'voided')->exists()) {
            return 'has a voided payment';
        }

        $payments = DB::table('repayments')->where('loan_id', $loanId)->where('status', 'posted')->orderBy('id')->get();

        if (DB::table('repayment_allocations')->whereIn('repayment_id', $payments->pluck('id'))->exists()) {
            return 'some payments already recorded';
        }

        $periods = DB::table('amortization_schedules')->where('loan_id', $loanId)->orderBy('period_number')->orderBy('id')->get();
        $placed = [];
        $filled = [];

        foreach (self::COMPONENTS as $component => [$total, , $capacity]) {
            foreach ($payments as $payment) {
                $left = $this->centavos($payment->{$total});

                foreach ($periods as $period) {
                    $room = $this->centavos($period->{$capacity}) - ($filled[$period->id][$component] ?? 0);
                    $take = min(max(0, $room), $left);

                    if ($take > 0) {
                        $filled[$period->id][$component] = ($filled[$period->id][$component] ?? 0) + $take;
                        $placed[$payment->id][$period->id][$component] = $take;
                        $left -= $take;
                    }
                }

                if ($left > 0) {
                    return "payment {$payment->id} has {$component} the periods cannot hold";
                }
            }
        }

        foreach ($periods as $period) {
            foreach (self::COMPONENTS as $component => [, $paid]) {
                if (($filled[$period->id][$component] ?? 0) !== $this->centavos($period->{$paid})) {
                    return "period {$period->period_number} {$component} paid does not match the payments";
                }
            }
        }

        return $this->rows($payments, $periods, $placed);
    }

    /**
     * @param  array<int, array<int, array<string, int>>>  $placed
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $payments, Collection $periods, array $placed): array
    {
        $now = now();
        $periodNumbers = $periods->pluck('period_number', 'id');
        $rows = [];

        foreach ($payments as $payment) {
            foreach ($placed[$payment->id] ?? [] as $periodId => $amounts) {
                $rows[] = [
                    'repayment_id' => $payment->id,
                    'amortization_schedule_id' => $periodId,
                    'period_number' => $periodNumbers[$periodId],
                    'penalty' => ($amounts['penalty'] ?? 0) / 100,
                    'interest' => ($amounts['interest'] ?? 0) / 100,
                    'principal' => ($amounts['principal'] ?? 0) / 100,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $rows;
    }

    private function centavos(mixed $pesos): int
    {
        return (int) round((float) $pesos * 100);
    }

    /**
     * Rows a payment wrote for itself cannot be told apart from rows this
     * wrote, so they all stay.
     */
    public function down(): void {}
};
