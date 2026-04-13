<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\Repayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function stats(): array
    {
        $monthStart = Carbon::now()->startOfMonth();

        $totalPortfolio = (float) Loan::whereIn('status', ['released', 'ongoing', 'completed'])
            ->sum('principal_amount');

        $activeLoansCount = Loan::whereIn('status', ['released', 'ongoing'])->count();

        $totalCollectedMtd = (float) Repayment::where('status', 'posted')
            ->whereDate('payment_date', '>=', $monthStart)
            ->sum('amount_paid');

        $overdueLoansCount = (int) DB::table('amortization_schedules')
            ->where('status', 'overdue')
            ->distinct('loan_id')
            ->count('loan_id');

        // Cache sparklines for 10 minutes (28 queries → 0 on cache hit)
        $sparklines = Cache::remember('dashboard:sparklines', 600, fn () => [
            'portfolio' => $this->portfolioSparkline(),
            'active_loans' => $this->activeLoansSparkline(),
            'collected' => $this->collectionsSparkline(),
            'overdue' => $this->overdueSparkline(),
        ]);

        return [
            'total_portfolio' => round($totalPortfolio, 2),
            'active_loans_count' => $activeLoansCount,
            'total_collected_mtd' => round($totalCollectedMtd, 2),
            'overdue_loans_count' => $overdueLoansCount,
            'sparklines' => $sparklines,
        ];
    }

    public function collectionsTrend(string $period = 'week'): array
    {
        return match ($period) {
            'month' => $this->monthlyTrend(),
            'year' => $this->yearlyTrend(),
            default => $this->weeklyTrend(),
        };
    }

    private function weeklyTrend(): array
    {
        $points = collect();
        for ($i = 11; $i >= 0; $i--) {
            $start = Carbon::now()->startOfWeek()->subWeeks($i);
            $end = $start->copy()->endOfWeek();

            $total = (float) Repayment::where('status', 'posted')
                ->whereBetween('payment_date', [$start, $end])
                ->sum('amount_paid');

            $points->push([
                'period' => 'week',
                'key' => 'W'.(12 - $i),
                'label' => $start->format('M j'),
                'total' => round($total, 2),
            ]);
        }

        return ['data' => $points->values()->toArray()];
    }

    private function monthlyTrend(): array
    {
        $points = collect();
        for ($i = 11; $i >= 0; $i--) {
            $start = Carbon::now()->startOfMonth()->subMonths($i);
            $end = $start->copy()->endOfMonth();

            $total = (float) Repayment::where('status', 'posted')
                ->whereBetween('payment_date', [$start, $end])
                ->sum('amount_paid');

            $points->push([
                'period' => 'month',
                'key' => $start->format('Y-m'),
                'label' => $start->format('M Y'),
                'total' => round($total, 2),
            ]);
        }

        return ['data' => $points->values()->toArray()];
    }

    private function yearlyTrend(): array
    {
        $points = collect();
        for ($i = 4; $i >= 0; $i--) {
            $start = Carbon::now()->startOfYear()->subYears($i);
            $end = $start->copy()->endOfYear();

            $total = (float) Repayment::where('status', 'posted')
                ->whereBetween('payment_date', [$start, $end])
                ->sum('amount_paid');

            $points->push([
                'period' => 'year',
                'key' => (string) $start->year,
                'label' => (string) $start->year,
                'total' => round($total, 2),
            ]);
        }

        return ['data' => $points->values()->toArray()];
    }

    public function dailyDues(?string $date = null): array
    {
        $targetDate = $date ? Carbon::parse($date) : Carbon::today();

        $schedules = DB::table('amortization_schedules')
            ->join('loans', 'amortization_schedules.loan_id', '=', 'loans.id')
            ->join('borrowers', 'loans.borrower_id', '=', 'borrowers.id')
            ->whereDate('amortization_schedules.due_date', $targetDate)
            ->whereIn('loans.status', ['released', 'ongoing'])
            ->select(
                'amortization_schedules.id',
                'amortization_schedules.loan_id',
                'amortization_schedules.total_due',
                'amortization_schedules.principal_paid',
                'amortization_schedules.interest_paid',
                'amortization_schedules.penalty_paid',
                'loans.loan_account_number',
                DB::raw("CONCAT(borrowers.first_name, ' ', borrowers.last_name) as borrower_name")
            )
            ->get();

        $items = $schedules->map(function ($s) {
            $amountDue = (float) $s->total_due;
            $amountPaid = (float) $s->principal_paid + (float) $s->interest_paid + (float) $s->penalty_paid;

            if ($amountPaid >= $amountDue) {
                $status = 'collected';
            } elseif ($amountPaid > 0) {
                $status = 'partial';
            } else {
                $status = 'pending';
            }

            return [
                'loan_id' => $s->loan_id,
                'loan_account_number' => $s->loan_account_number,
                'borrower_name' => $s->borrower_name,
                'amount_due' => round($amountDue, 2),
                'amount_paid' => round($amountPaid, 2),
                'status' => $status,
            ];
        });

        $totalDue = $items->sum('amount_due');
        $totalCollected = $items->where('status', 'collected')->sum('amount_due')
            + $items->where('status', 'partial')->sum('amount_paid');

        return [
            'data' => $items->values()->toArray(),
            'summary' => [
                'total_due' => round($totalDue, 2),
                'total_collected' => round($totalCollected, 2),
                'collection_rate' => $totalDue > 0 ? round($totalCollected / $totalDue * 100, 1) : 0,
                'uncollected' => round(max(0, $totalDue - $totalCollected), 2),
            ],
        ];
    }

    public function recentTransactions(int $limit = 10): array
    {
        $payments = Repayment::with('loan.borrower')
            ->where('status', 'posted')
            ->latest('payment_date')
            ->take($limit)
            ->get()
            ->map(fn ($r) => [
                'type' => 'payment',
                'borrower_name' => $r->loan?->borrower?->full_name,
                'description' => 'Payment via '.ucwords(str_replace('_', ' ', $r->method)),
                'amount' => (float) $r->amount_paid,
                'date' => $r->payment_date?->format('M j, g:i A'),
                'created_at' => $r->created_at,
            ]);

        $releases = Loan::with('borrower')
            ->whereNotNull('released_at')
            ->latest('released_at')
            ->take($limit)
            ->get()
            ->map(fn ($l) => [
                'type' => 'release',
                'borrower_name' => $l->borrower?->full_name,
                'description' => 'Loan released',
                'amount' => (float) $l->net_proceeds,
                'date' => $l->released_at?->format('M j, g:i A'),
                'created_at' => $l->released_at,
            ]);

        $merged = $payments->concat($releases)
            ->sortByDesc('created_at')
            ->take($limit)
            ->values()
            ->map(fn ($item) => collect($item)->except('created_at')->toArray());

        return ['data' => $merged->toArray()];
    }

    private function portfolioSparkline(): array
    {
        return collect(range(6, 0))->map(function ($weeksAgo) {
            $end = Carbon::now()->startOfWeek()->subWeeks($weeksAgo)->endOfWeek();
            $value = (float) Loan::whereIn('status', ['released', 'ongoing', 'completed'])
                ->where('released_at', '<=', $end)
                ->sum('principal_amount');

            return ['v' => round($value / 1000, 0)];
        })->toArray();
    }

    private function activeLoansSparkline(): array
    {
        return collect(range(6, 0))->map(function ($weeksAgo) {
            $end = Carbon::now()->startOfWeek()->subWeeks($weeksAgo)->endOfWeek();
            $value = Loan::where('status', 'released')
                ->where('released_at', '<=', $end)
                ->count();

            return ['v' => $value];
        })->toArray();
    }

    private function collectionsSparkline(): array
    {
        return collect(range(6, 0))->map(function ($daysAgo) {
            $day = Carbon::today()->subDays($daysAgo);
            $value = (float) Repayment::where('status', 'posted')
                ->whereDate('payment_date', $day)
                ->sum('amount_paid');

            return ['v' => round($value / 1000, 0)];
        })->toArray();
    }

    private function overdueSparkline(): array
    {
        return collect(range(6, 0))->map(function ($daysAgo) {
            $day = Carbon::today()->subDays($daysAgo);
            $value = (int) DB::table('amortization_schedules')
                ->where('status', 'overdue')
                ->where('due_date', '<=', $day)
                ->distinct('loan_id')
                ->count('loan_id');

            return ['v' => $value];
        })->toArray();
    }
}
