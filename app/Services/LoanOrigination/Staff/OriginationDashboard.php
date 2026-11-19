<?php

namespace App\Services\LoanOrigination\Staff;

use App\Models\OriginationApplication;
use App\Models\User;
use App\Services\Dashboard\ApplicationTurnaround;
use App\Services\Dashboard\LoanScope;
use App\Services\LoanOrigination\ApplicationTransitions;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The loan origination dashboard (LO-B3). Every figure is the server's, for
 * the given branches (null: every branch), on Manila days, and leaves out
 * applications from links marked "Test" (decision 17). Each is one grouped
 * query; nothing is summed in PHP over rows.
 *
 * - Status counts (`kpis` and `by_status`) are the current status of the
 *   applications started in the period. `incomplete` is draft plus
 *   incomplete; `pending_documents` is under document verification.
 * - `new` and `amount_requested` are the applications submitted in the
 *   period and their requested amounts. `amount_approved` is the principal
 *   of the loans they became that were approved in the period.
 * - `completion_rate`: of the applications started in the period, the share
 *   submitted. `approval_rate`: of the applications approved or rejected in
 *   the period (their history), the share approved. `avg_processing_hours`:
 *   submitted to decided, for those decided in the period (expiry left out).
 *   Rates and hours are rounded half up to one decimal; with nothing to
 *   measure they read null.
 * - `stage_times`: the average hours an application spent in each status,
 *   over stays that ended in the period, read from the status history with
 *   a window function.
 * - `turnaround` is EB-D2's ApplicationTurnaround on the loans the
 *   applications became, so it reads as the executive dashboard does.
 * - `previous` holds the KPIs for the period of the same length just before.
 * - Money is a two-decimal string.
 */
final class OriginationDashboard
{
    private const STATUS_KPIS = [
        'submitted' => 'submitted',
        'pending_documents' => 'document_verification',
        'additional_info_required' => 'additional_info_required',
        'under_assessment' => 'under_assessment',
        'pending_approval' => 'pending_approval',
        'approved' => 'approved',
        'rejected' => 'rejected',
        'withdrawn' => 'withdrawn',
        'converted' => 'converted',
        'expired' => 'expired',
    ];

    public function __construct(
        private readonly ApplicationTurnaround $turnaround,
        private readonly ApplicationPresenter $presenter,
    ) {}

    /**
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    public function build(string $from, string $to, ?array $branchIds, User $viewer): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->addDay()->startOfDay();
        $days = (int) $start->diffInDays($end);
        $previousStart = $start->copy()->subDays($days);

        $statuses = $this->statusCounts($branchIds, $start, $end);

        return [
            'period' => ['from' => $start->toDateString(), 'to' => $end->copy()->subDay()->toDateString()],
            'previous_period' => ['from' => $previousStart->toDateString(), 'to' => $start->copy()->subDay()->toDateString()],
            'kpis' => $this->kpis($branchIds, $start, $end, $statuses),
            'previous' => $this->kpis($branchIds, $previousStart, $start),
            'trends' => $this->trends($branchIds, $start, $end),
            'by_status' => collect(OriginationApplication::STATUSES)->map(fn (string $status) => [
                'status' => $status,
                'label' => ApplicationTransitions::label($status),
                'count' => $statuses[$status] ?? 0,
            ])->values()->all(),
            'by_product' => $this->byProduct($branchIds, $start, $end),
            'decisions' => $this->decisions($branchIds, $start, $end),
            'stage_times' => $this->stageTimes($branchIds, $start, $end),
            'turnaround' => $this->turnaround->build(
                $start->toDateString(),
                $end->copy()->subDay()->toDateString(),
                $branchIds,
                LoanScope::none(),
                fn (Builder $loans) => $loans->whereIn('loans.id', $this->base($branchIds)->whereNotNull('loan_id')->select('loan_id')),
            ),
            'recent' => $this->base($branchIds)
                ->whereNotNull('submitted_at')
                ->with(['branch:id,name', 'loanProduct:id,name', 'assignedOfficer:id,first_name,last_name'])
                ->orderByDesc('submitted_at')->orderByDesc('id')
                ->limit(10)->get()
                ->map(fn (OriginationApplication $application) => $this->presenter->summary($application, $viewer))
                ->values()->all(),
        ];
    }

    /**
     * @param  list<int>|null  $branchIds
     * @param  array<string, int>|null  $statuses
     * @return array<string, int|string|float|null>
     */
    private function kpis(?array $branchIds, Carbon $start, Carbon $end, ?array $statuses = null): array
    {
        $statuses ??= $this->statusCounts($branchIds, $start, $end);
        $total = array_sum($statuses);

        $startedSubmitted = $this->started($branchIds, $start, $end)->whereNotNull('submitted_at')->count();

        $submitted = $this->base($branchIds)
            ->where('submitted_at', '>=', $start)->where('submitted_at', '<', $end)
            ->selectRaw('COUNT(*) as applications, COALESCE(SUM(requested_amount), 0) as requested')
            ->toBase()->first();

        $processing = $this->base($branchIds)
            ->whereNotNull('submitted_at')
            ->where('status', '!=', 'expired')
            ->where('decided_at', '>=', $start)->where('decided_at', '<', $end)
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, submitted_at, decided_at)) as seconds')
            ->value('seconds');

        $decided = $this->historyIn($branchIds, $start, $end)
            ->whereIn('h.to_status', ['approved', 'rejected'])
            ->selectRaw('h.to_status, COUNT(DISTINCT h.application_id) as applications')
            ->groupBy('h.to_status')
            ->pluck('applications', 'to_status');
        $approved = (int) ($decided['approved'] ?? 0);
        $rejected = (int) ($decided['rejected'] ?? 0);

        $amountApproved = DB::table('loans')
            ->whereIn('loans.id', $this->base($branchIds)->whereNotNull('loan_id')->select('loan_id'))
            ->where('loans.approved_at', '>=', $start)->where('loans.approved_at', '<', $end)
            ->sum('loans.principal_amount');

        $kpis = [
            'total' => $total,
            'new' => (int) ($submitted->applications ?? 0),
            'incomplete' => ($statuses['draft'] ?? 0) + ($statuses['incomplete'] ?? 0),
        ];

        foreach (self::STATUS_KPIS as $key => $status) {
            $kpis[$key] = $statuses[$status] ?? 0;
        }

        return [
            ...$kpis,
            'amount_requested' => $this->pesos($submitted->requested ?? '0'),
            'amount_approved' => $this->pesos($amountApproved),
            'avg_processing_hours' => $processing === null ? null : $this->oneDecimal((float) $processing / 3600),
            'completion_rate' => $total === 0 ? null : $this->oneDecimal($startedSubmitted * 100 / $total),
            'approval_rate' => $approved + $rejected === 0 ? null : $this->oneDecimal($approved * 100 / ($approved + $rejected)),
        ];
    }

    /**
     * @param  list<int>|null  $branchIds
     * @return array<string, int>
     */
    private function statusCounts(?array $branchIds, Carbon $start, Carbon $end): array
    {
        return $this->started($branchIds, $start, $end)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Each day of the period: applications submitted (completed) and those
     * started that day still unsubmitted (incomplete).
     *
     * @param  list<int>|null  $branchIds
     * @return list<array{date: string, completed: int, incomplete: int}>
     */
    private function trends(?array $branchIds, Carbon $start, Carbon $end): array
    {
        $completed = $this->base($branchIds)
            ->where('submitted_at', '>=', $start)->where('submitted_at', '<', $end)
            ->selectRaw('DATE(submitted_at) as day, COUNT(*) as aggregate')
            ->groupByRaw('DATE(submitted_at)')
            ->pluck('aggregate', 'day');

        $incomplete = $this->started($branchIds, $start, $end)
            ->whereIn('status', OriginationApplication::UNSUBMITTED_STATUSES)
            ->selectRaw('DATE(COALESCE(started_at, created_at)) as day, COUNT(*) as aggregate')
            ->groupByRaw('DATE(COALESCE(started_at, created_at))')
            ->pluck('aggregate', 'day');

        $rows = [];

        for ($day = $start->copy(); $day->lt($end); $day->addDay()) {
            $date = $day->toDateString();
            $rows[] = ['date' => $date, 'completed' => (int) ($completed[$date] ?? 0), 'incomplete' => (int) ($incomplete[$date] ?? 0)];
        }

        return $rows;
    }

    /**
     * @param  list<int>|null  $branchIds
     * @return list<array{product_id: ?int, product: string, count: int}>
     */
    private function byProduct(?array $branchIds, Carbon $start, Carbon $end): array
    {
        return $this->started($branchIds, $start, $end)
            ->leftJoin('loan_products', 'loan_products.id', '=', 'origination_applications.loan_product_id')
            ->selectRaw('origination_applications.loan_product_id as product_id, loan_products.name as product, COUNT(*) as aggregate')
            ->groupBy('origination_applications.loan_product_id', 'loan_products.name')
            ->orderByDesc('aggregate')
            ->toBase()->get()
            ->map(fn ($row) => [
                'product_id' => $row->product_id === null ? null : (int) $row->product_id,
                'product' => $row->product ?? 'No product chosen yet',
                'count' => (int) $row->aggregate,
            ])->values()->all();
    }

    /**
     * @param  list<int>|null  $branchIds
     * @return list<array{date: string, approved: int, rejected: int}>
     */
    private function decisions(?array $branchIds, Carbon $start, Carbon $end): array
    {
        $rows = $this->historyIn($branchIds, $start, $end)
            ->whereIn('h.to_status', ['approved', 'rejected'])
            ->selectRaw('DATE(h.created_at) as day, h.to_status, COUNT(DISTINCT h.application_id) as applications')
            ->groupByRaw('DATE(h.created_at), h.to_status')
            ->get();

        $byDay = [];

        foreach ($rows as $row) {
            $byDay[$row->day] ??= ['date' => (string) $row->day, 'approved' => 0, 'rejected' => 0];
            $byDay[$row->day][$row->to_status] = (int) $row->applications;
        }

        ksort($byDay);

        return array_values($byDay);
    }

    /**
     * @param  list<int>|null  $branchIds
     * @return list<array{status: string, label: string, avg_hours: float, stays: int}>
     */
    private function stageTimes(?array $branchIds, Carbon $start, Carbon $end): array
    {
        if ($branchIds === []) {
            return [];
        }

        $branchSql = $branchIds === null ? '' : ' and a.branch_id in ('.implode(',', array_fill(0, count($branchIds), '?')).')';

        $rows = DB::select(
            'select stage, avg(timestampdiff(second, entered_at, left_at)) as seconds, count(*) as stays from ('
            .'select h.to_status as stage, h.created_at as entered_at, '
            .'lead(h.created_at) over (partition by h.application_id order by h.id) as left_at '
            .'from origination_status_history h join origination_applications a on a.id = h.application_id '
            .'where a.is_test = 0'.$branchSql
            .') stays where left_at >= ? and left_at < ? group by stage',
            [...($branchIds ?? []), $start->toDateTimeString(), $end->toDateTimeString()],
        );

        $byStage = collect($rows)->keyBy('stage');

        return collect(OriginationApplication::STATUSES)
            ->filter(fn (string $status) => $byStage->has($status))
            ->map(fn (string $status) => [
                'status' => $status,
                'label' => ApplicationTransitions::label($status),
                'avg_hours' => $this->oneDecimal((float) $byStage[$status]->seconds / 3600),
                'stays' => (int) $byStage[$status]->stays,
            ])->values()->all();
    }

    /**
     * @param  list<int>|null  $branchIds
     * @return Builder<OriginationApplication>
     */
    private function base(?array $branchIds): Builder
    {
        return OriginationApplication::query()
            ->where('origination_applications.is_test', false)
            ->when($branchIds !== null, fn (Builder $q) => $q->whereIn('origination_applications.branch_id', $branchIds));
    }

    /**
     * @param  list<int>|null  $branchIds
     * @return Builder<OriginationApplication>
     */
    private function started(?array $branchIds, Carbon $start, Carbon $end): Builder
    {
        return $this->base($branchIds)
            ->whereRaw('COALESCE(origination_applications.started_at, origination_applications.created_at) >= ?', [$start])
            ->whereRaw('COALESCE(origination_applications.started_at, origination_applications.created_at) < ?', [$end]);
    }

    /**
     * Status history rows written in the period, of the applications in
     * scope, read through the query builder (never written).
     *
     * @param  list<int>|null  $branchIds
     */
    private function historyIn(?array $branchIds, Carbon $start, Carbon $end): \Illuminate\Database\Query\Builder
    {
        return DB::table('origination_status_history as h')
            ->join('origination_applications as a', 'a.id', '=', 'h.application_id')
            ->where('a.is_test', false)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('a.branch_id', $branchIds))
            ->where('h.created_at', '>=', $start)->where('h.created_at', '<', $end);
    }

    private function pesos(mixed $amount): string
    {
        return (string) BigDecimal::of((string) ($amount ?? '0'))->toScale(2, RoundingMode::HalfUp);
    }

    private function oneDecimal(float $value): float
    {
        return round($value, 1, PHP_ROUND_HALF_UP);
    }
}
