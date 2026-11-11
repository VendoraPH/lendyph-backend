<?php

namespace App\Services\Portal\Read;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\Repayment;
use App\Services\AmortizationBalanceService;
use App\Services\Portal\MemberScope;
use App\Services\RepaymentService;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A member's loans as the portal shows them (portal design §1, §3).
 *
 * - Only the member's own ever-released loans (Loan::EVER_RELEASED_STATUSES):
 *   never a draft or an application in approval, never a loan where the
 *   member is only a co-maker (those belong to another member).
 * - Every figure is the staff loan screen's: the loan summary
 *   (RepaymentService::getLoanSummary()), the Amortization Balance tab
 *   (AmortizationBalanceService) and the posted repayments, sent as
 *   two-decimal strings. The portal adds nothing up itself.
 * - Never the payment presets, the payoff figure, the restructurable balance,
 *   collateral, the account officer or approval notes.
 */
final class LoanReader
{
    public function __construct(
        private readonly MemberScope $scope,
        private readonly RepaymentService $repayments,
        private readonly AmortizationBalanceService $balances,
    ) {}

    /** @return Builder<Loan> the signed-in member's ever-released loans */
    public function query(): Builder
    {
        return Loan::query()
            ->where('loans.borrower_id', $this->scope->borrowerId())
            ->whereIn('loans.status', Loan::EVER_RELEASED_STATUSES);
    }

    /** The member's loan with this id, or 404. */
    public function find(int $id): Loan
    {
        return $this->query()->findOrFail($id);
    }

    /** @return list<array<string, mixed>> newest release first */
    public function list(): array
    {
        return $this->query()
            ->with(['loanProduct:id,name', 'amortizationSchedules'])
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Loan $loan) => $this->row($loan, $this->repayments->getLoanSummary($loan)))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function detail(Loan $loan): array
    {
        $loan->loadMissing(['loanProduct:id,name', 'amortizationSchedules']);
        $summary = $this->repayments->getLoanSummary($loan);

        return [
            ...$this->row($loan, $summary),
            'term' => $loan->term,
            'term_unit' => $loan->term_unit?->value,
            'frequency' => $loan->frequency,
            'interest_rate' => $loan->getRawOriginal('interest_rate'),
            'interest_rate_frequency' => $loan->interest_rate_frequency?->value,
            'interest_method' => $loan->interest_method,
            'outstanding_interest' => Pesos::of($summary['outstanding_interest']),
            'outstanding_penalty' => Pesos::of($summary['outstanding_penalty']),
            'insurance_owed' => Pesos::of($summary['insurance_owed']),
            'total_repaid' => Pesos::of($summary['total_repaid']),
            'principal_paid' => Pesos::of($summary['principal_paid']),
            'interest_paid' => Pesos::of($summary['interest_paid']),
            'penalty_paid' => Pesos::of($summary['penalty_paid']),
        ];
    }

    /**
     * The Amortization Balance tab's periods and totals, with each period's
     * status for a member: paid, partial, due (on or before today, Manila, not
     * yet late), past_due (late: past the due date and the grace period),
     * upcoming, or closed (a period an extension or restructure closed), and
     * its `penalty_months` (the monthly late penalty charges in its penalty).
     *
     * @return array{periods: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function schedule(Loan $loan): array
    {
        $data = $this->balances->forLoan($loan);
        $today = Carbon::today()->toDateString();
        // Each period's monthly penalty charge count, as the staff schedule sends it.
        $penaltyMonths = $loan->amortizationSchedules()->reorder()->pluck('penalty_months', 'id');

        $money = fn (array $parts): array => array_map(fn ($value) => Pesos::of($value), $parts);

        $periods = array_map(fn (array $period): array => [
            'period_number' => $period['period_number'],
            'due_date' => $period['due_date'],
            'status' => $this->periodStatus($period, $today),
            'principal' => $money($period['principal']),
            'interest' => $money($period['interest']),
            'penalty' => $money($period['penalty']),
            'balance' => Pesos::of($period['balance']),
            'penalty_months' => (int) ($penaltyMonths[$period['id']] ?? 0),
        ], $data['periods']);

        $totals = $data['totals'];

        return [
            'periods' => $periods,
            'totals' => [
                'principal' => $money($totals['principal']),
                'interest' => $money($totals['interest']),
                'penalty' => $money($totals['penalty']),
                'paid' => Pesos::of($totals['paid']),
                'insurance_owed' => Pesos::of($totals['insurance_owed']),
                'balance' => Pesos::of($totals['balance']),
                'overdue' => Pesos::of($totals['overdue']),
            ],
        ];
    }

    /**
     * Posted (not voided) payments, newest first: on one loan, or on every
     * loan the member can see here.
     *
     * @return LengthAwarePaginator<int, Repayment>
     */
    public function payments(?Loan $loan, int $perPage): LengthAwarePaginator
    {
        return Repayment::query()
            ->whereIn('loan_id', $loan === null ? $this->query()->select('loans.id') : [$loan->id])
            ->where('status', 'posted')
            ->with('loan:id,loan_account_number')
            ->withSum('shareCapitalLedgerEntries as build_up_credit', 'credit')
            ->withSum('shareCapitalLedgerEntries as build_up_debit', 'debit')
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  Collection<int, Repayment>  $rows
     * @return list<array<string, mixed>>
     */
    public function paymentRows(Collection $rows): array
    {
        return $rows->map(fn (Repayment $r): array => [
            'receipt_number' => $r->receipt_number,
            'loan_account_number' => $r->loan?->loan_account_number,
            'payment_date' => $r->payment_date?->toDateString(),
            'amount_paid' => Pesos::of($r->getRawOriginal('amount_paid')),
            'principal' => Pesos::of($r->getRawOriginal('principal_applied')),
            'interest' => Pesos::of($r->getRawOriginal('interest_applied')),
            'penalty' => Pesos::of($r->getRawOriginal('penalty_applied')),
            'insurance' => Pesos::of($r->getRawOriginal('insurance_applied') ?? '0'),
            // Net, as the staff payment list's `scb_paid`: what the payment
            // credited to share capital.
            'build_up' => Pesos::fromCentavos(Pesos::centavos((string) ($r->build_up_credit ?? '0')) - Pesos::centavos((string) ($r->build_up_debit ?? '0'))),
            'method' => $r->method,
            'reference_number' => $r->reference_number,
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $summary  RepaymentService::getLoanSummary()
     * @return array<string, mixed>
     */
    private function row(Loan $loan, array $summary): array
    {
        return [
            'id' => $loan->id,
            'loan_account_number' => $loan->loan_account_number,
            'product_name' => $loan->loanProduct?->name,
            'display_status' => $loan->displayStatus(),
            'release_date' => $loan->released_at?->toDateString(),
            'maturity_date' => $loan->maturity_date?->toDateString(),
            'principal_amount' => Pesos::of($loan->getRawOriginal('principal_amount')),
            'total_outstanding' => Pesos::of($summary['total_outstanding']),
            'outstanding_principal' => Pesos::of($summary['outstanding_principal']),
            'overdue_amount' => Pesos::of($summary['overdue_amount']),
            'next_due_date' => $summary['next_due_date'],
            'next_due_amount' => Pesos::of($summary['next_due_amount']),
            'paid_percent' => $this->paidPercent($loan, $summary),
            // The monthly late penalty charges counted on the loan's late
            // periods (the loan summary's `penalty_months`).
            'penalty_months' => (int) $summary['penalty_months'],
        ];
    }

    /**
     * The principal paid as a whole percent of the principal, floored and
     * kept between 0 and 100, in whole centavos (the loan card's bar).
     *
     * @param  array<string, mixed>  $summary
     */
    private function paidPercent(Loan $loan, array $summary): int
    {
        $principal = Pesos::centavos((string) $loan->getRawOriginal('principal_amount'));

        if ($principal <= 0) {
            return 0;
        }

        return max(0, min(100, intdiv(Pesos::centavos($summary['principal_paid']) * 100, $principal)));
    }

    /** @param  array<string, mixed>  $period  one AmortizationBalanceService period */
    private function periodStatus(array $period, string $today): string
    {
        if ($period['status'] === 'paid') {
            return 'paid';
        }

        if (! in_array($period['status'], AmortizationSchedule::UNPAID_STATUSES, true)) {
            return 'closed';
        }

        if ($period['is_late']) {
            return 'past_due';
        }

        if ($period['status'] === 'partial') {
            return 'partial';
        }

        return $period['due_date'] <= $today ? 'due' : 'upcoming';
    }
}
