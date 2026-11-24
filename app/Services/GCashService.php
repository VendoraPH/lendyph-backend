<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchClosure;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
use App\Models\User;
use App\Rules\BranchTakesNew;
use App\Services\Accounting\AutomaticPoster;
use App\Services\Accounting\Money;
use App\Services\Branches\BranchWriteGuard;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GCashService
{
    /** The 409 when recording collides with another save on a lock. Nothing was saved. */
    public const CREATE_CONFLICT = 'Another GCash transaction was saved at the same moment. Nothing was saved; check the list, then submit again.';

    /**
     * The reference number's unique index. Two creates that read the same
     * newest number collide on it; the second answers CREATE_CONFLICT.
     */
    public const REFERENCE_NO_INDEX = 'gcash_transactions_reference_no_unique';

    /** The 409 when mark-paid collides with another change to the transaction. */
    public const MARK_PAID_CONFLICT = 'This transaction was changed at the same moment. Nothing was saved; reload it and try again.';

    public function __construct(private AutomaticPoster $poster) {}

    /**
     * Records a Cash In or Cash Out. In the same transaction, a Cash Out and a
     * Cash In paid at the counter post their journal (Phase 2, decision 6):
     * Cash In Dr 1010 amount + charge / Cr 1020 amount / Cr 4090 charge, dated
     * `paid_at`; Cash Out Dr 1020 amount / Cr 1010 amount − charge / Cr 4090
     * charge, dated `transaction_date`. A pending Cash In posts nothing until
     * markPaid().
     *
     * A walk-in transaction recorded in a branch first locks that branch's
     * row and re-checks it (lockBranchForWalkIn(), Finish run round 2,
     * decision 4), the row a closure request and its approval lock first, so
     * the two never interleave. Then the reference lock (generateReferenceNo())
     * is the first statement on gcash_transactions, and the duplicate check
     * runs under it as a locking read (Lead's call 5). So an identical create
     * can't commit between the check and this insert, and the check reads what
     * any create before it committed: two identical creates are never both
     * saved.
     *
     * A deadlock, a lock wait timeout or a collision on the reference number
     * answers 409 with nothing saved, never 500, and is not tried again: the
     * duplicate check would have to run again against whatever the other save
     * committed.
     */
    public function createTransaction(array $payload, User $actor): GCashTransaction
    {
        $isPending = (bool) ($payload['is_pending'] ?? false);

        return LoanWriteTransaction::run(function () use ($payload, $actor, $isPending) {
            if (isset($payload['gcash_non_member_id']) && isset($payload['branch_id'])) {
                $this->lockBranchForWalkIn((int) $payload['branch_id']);
            }

            $now = now();
            $referenceNo = $this->generateReferenceNo($now);

            [
                'type' => $type,
                'amount' => $amount,
                'charge_amount' => $charge,
                'total_amount' => $total,
            ] = $this->quote($payload['type'], $payload['amount']);

            if ($duplicate = $this->detectDuplicate($payload, $type, $amount)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Possible duplicate transaction within the last 60 seconds.',
                    'data' => ['existing_id' => $duplicate->id],
                ], 409));
            }

            $status = match (true) {
                $type === 'cash_out' => 'completed',
                $type === 'cash_in' && $isPending => 'pending',
                default => 'paid',
            };

            $tx = GCashTransaction::create([
                'reference_no' => $referenceNo,
                'transaction_date' => $now,
                'type' => $type,
                'amount' => $amount,
                'charge_amount' => $charge,
                'total_amount' => $total,
                'status' => $status,
                // A Cash In recorded as paid was paid at the counter, now.
                // markPaid() stamps the same column when a pending one settles.
                'paid_at' => $status === 'paid' ? $now : null,
                'borrower_id' => $payload['borrower_id'] ?? null,
                'gcash_non_member_id' => $payload['gcash_non_member_id'] ?? null,
                // A walk-in's branch, resolved by StoreGCashTransactionRequest;
                // a member's transaction follows the member's branch instead.
                'branch_id' => isset($payload['gcash_non_member_id']) ? ($payload['branch_id'] ?? null) : null,
                'transactor_user_id' => $actor->id,
                'remarks' => $payload['remarks'] ?? null,
            ]);

            AuditLogService::log(
                action: 'created',
                auditable: $tx,
                newValues: $tx->toArray(),
                // Printed as stored on the Audit Trail, so words, not the type and status keys.
                description: 'GCash '.Str::headline($type).' '.$tx->reference_no.' recorded ('.Str::headline($status).')',
            );

            if ($status !== 'pending') {
                $this->poster->gcashTransaction($tx, $actor->id);
            }

            return $tx;
        }, racedUniqueIndex: self::REFERENCE_NO_INDEX, conflictMessage: self::CREATE_CONFLICT);
    }

    /**
     * The charge and total for a transaction of this type and amount, from
     * the fee tier the amount falls in. createTransaction() records exactly
     * this, and the preview endpoint returns it, so the two never disagree.
     * Cash In adds the charge to the amount; Cash Out deducts it.
     *
     * Writes nothing. Throws the same 422 on `amount` as recording would
     * when no tier covers the amount, and when a Cash Out's charge would take
     * all of it: the customer would receive nothing, or owe the difference.
     *
     * @return array{type: string, amount: float, charge_amount: float, total_amount: float}
     *
     * @throws ValidationException
     */
    public function quote(string $type, float|int|string $amount): array
    {
        $amount = round((float) $amount, 2);
        $tier = $this->resolveTier($amount);

        $charge = (float) ($type === 'cash_in' ? $tier->cash_in_rate : $tier->cash_out_rate);
        $total = $type === 'cash_in'
            ? round($amount + $charge, 2)
            : round($amount - $charge, 2);

        if ($type === 'cash_out' && $total <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['Amount must be more than the '.Money::format((int) round($charge * 100)).' charge.'],
            ]);
        }

        return [
            'type' => $type,
            'amount' => $amount,
            'charge_amount' => $charge,
            'total_amount' => $total,
        ];
    }

    /**
     * Settles a pending Cash In and posts its journal, dated `paid_at`.
     *
     * The row is locked and re-read first, so of two mark-paid requests only
     * the first finds it pending: the second answers the same 422 as a Cash In
     * that was already paid, and nothing posts twice. A deadlock or lock wait
     * timeout answers 409 with nothing saved, never 500.
     */
    public function markPaid(GCashTransaction $tx, User $actor): GCashTransaction
    {
        return LoanWriteTransaction::run(function () use ($tx, $actor): GCashTransaction {
            $locked = GCashTransaction::query()->whereKey($tx->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->type !== 'cash_in' || $locked->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ['Only pending Cash In transactions can be marked as paid.'],
                ]);
            }

            $old = ['status' => $locked->status];
            $changes = [
                'status' => 'paid',
                'paid_at' => now(),
                'paid_by_user_id' => $actor->id,
            ];

            // A member's Cash In recorded before rows carried a branch is
            // stamped now with the branch it posts in (Phase 6 MB-BE3), so it
            // stays with that branch's books if the member later moves.
            if ($locked->branch_id === null && $locked->borrower_id !== null) {
                $changes['branch_id'] = BranchAttribution::gcashPostingBranch($locked);
                $old['branch_id'] = null;
            }

            $locked->update($changes);

            AuditLogService::log(
                action: 'status_changed',
                auditable: $locked,
                oldValues: $old,
                newValues: [
                    'status' => $locked->status,
                    'paid_at' => $locked->paid_at?->toIso8601String(),
                    'paid_by_user_id' => $locked->paid_by_user_id,
                    ...(array_key_exists('branch_id', $old) ? ['branch_id' => $locked->branch_id] : []),
                ],
                description: "GCash {$locked->reference_no} marked as paid",
            );

            $this->poster->gcashTransaction($locked, $actor->id);

            return $locked->fresh();
        }, conflictMessage: self::MARK_PAID_CONFLICT);
    }

    public function replaceTiers(array $tiers): Collection
    {
        $sorted = collect($tiers)->sortBy('display_order')->values();

        $sorted->each(function (array $tier, int $i) use ($sorted) {
            if ($tier['max_amount'] <= $tier['min_amount']) {
                throw ValidationException::withMessages([
                    'tiers' => ['Each tier must have max_amount > min_amount.'],
                ]);
            }

            if ($i > 0 && $sorted[$i - 1]['max_amount'] >= $tier['min_amount']) {
                throw ValidationException::withMessages([
                    'tiers' => ['Tier ranges must not overlap.'],
                ]);
            }
        });

        return DB::transaction(function () use ($sorted) {
            GCashTier::query()->delete();

            foreach ($sorted as $tier) {
                GCashTier::create([
                    'min_amount' => $tier['min_amount'],
                    'max_amount' => $tier['max_amount'],
                    'cash_in_rate' => $tier['cash_in_rate'],
                    'cash_out_rate' => $tier['cash_out_rate'],
                    'display_order' => $tier['display_order'],
                ]);
            }

            $fresh = GCashTier::orderBy('display_order')->get();

            AuditLogService::log(
                action: 'updated',
                auditable: null,
                newValues: ['tiers' => $fresh->toArray()],
                description: AuditLogModules::GCASH_TIERS_REPLACED.' ('.$fresh->count().' rows)',
            );

            return $fresh;
        });
    }

    /**
     * Charge income earned in the range, plus how many of those transactions
     * were Cash In and Cash Out (Finish run round 2, decision 2), on the date
     * AutomaticPoster::gcashTransaction() books its 4090 credit:
     *
     * - a Cash In on the Manila day it was paid (`paid_at`), whenever it was
     *   recorded: one recorded on the 30th and paid on the 2nd counts on the
     *   2nd;
     * - a Cash Out, completed when recorded, on its `transaction_date`.
     *
     * A pending Cash In's charge is income in no period until it is paid. So
     * is a paid Cash In with no `paid_at` (one the paid_at backfill had to
     * skip), which the books never posted either. The rows come in that
     * income date's order.
     *
     * With a $viewer, every figure covers only the rows they may see
     * (GCashTransaction::scopeVisibleTo), so the list, the total and the
     * counts always agree.
     *
     * @return array{total_income: float, cash_in_count: int, cash_out_count: int, start_date: string, end_date: string, transactions: Collection<int, GCashTransaction>}
     */
    public function incomeReport(string $startDate, string $endDate, ?User $viewer = null): array
    {
        $bounds = [
            Carbon::parse($startDate, config('app.timezone'))->startOfDay(),
            Carbon::parse($endDate, config('app.timezone'))->endOfDay(),
        ];

        $query = GCashTransaction::query()
            ->where(fn (Builder $earned) => $earned
                ->where(fn (Builder $cashIn) => $cashIn
                    ->where('type', 'cash_in')
                    ->where('status', 'paid')
                    ->whereBetween('paid_at', $bounds))
                ->orWhere(fn (Builder $cashOut) => $cashOut
                    ->where('type', 'cash_out')
                    ->where('status', 'completed')
                    ->whereBetween('transaction_date', $bounds)))
            ->when($viewer !== null, fn ($q) => $q->visibleTo($viewer));

        $transactions = (clone $query)
            ->orderByRaw("CASE WHEN type = 'cash_in' THEN paid_at ELSE transaction_date END")
            ->orderBy('id')
            ->get();
        $totals = (clone $query)
            ->selectRaw('COALESCE(SUM(charge_amount), 0) AS total_income')
            ->selectRaw("COALESCE(SUM(type = 'cash_in'), 0) AS cash_in_count")
            ->selectRaw("COALESCE(SUM(type = 'cash_out'), 0) AS cash_out_count")
            ->toBase()
            ->first();

        return [
            'total_income' => round((float) $totals->total_income, 2),
            'cash_in_count' => (int) $totals->cash_in_count,
            'cash_out_count' => (int) $totals->cash_out_count,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'transactions' => $transactions,
        ];
    }

    /**
     * Pending Cash Ins, oldest first. With a $viewer, only the rows they may
     * see (GCashTransaction::scopeVisibleTo).
     *
     * @return Collection<int, GCashTransaction>
     */
    public function pendingReport(?User $viewer = null): Collection
    {
        return GCashTransaction::query()
            ->where('type', 'cash_in')
            ->where('status', 'pending')
            ->when($viewer !== null, fn ($q) => $q->visibleTo($viewer))
            ->orderBy('transaction_date')
            ->get();
    }

    /**
     * The GCash Transactions list's totals (GET /gcash/transactions
     * `meta.totals`): every row $matching holds, on every page, in one SQL
     * aggregate, so the report prints the server's figures and the browser
     * never adds rows up.
     *
     * $matching is the list's own query, its scope and every filter, `status`
     * included. So a pending Cash In counts whenever the status filter lets it
     * into the list, as "All" does, and `pending` says how much of each figure
     * is still pending: a pending Cash In's charge is not income until it is
     * paid, which is why incomeReport() leaves it out.
     *
     * - `amount`: the Amount column, Cash In and Cash Out together.
     * - `cash_in`, `cash_out`: the amounts of each type.
     * - `charges`: the Charge column.
     * - `total`: the Total column, each Cash In's amount plus its charge and
     *   each Cash Out's amount less its charge.
     *
     * The columns are DECIMAL pesos, so the sums are exact. Each goes out as a
     * two-decimal string.
     *
     * @param  Builder<GCashTransaction>  $matching  the list's query, before its eager loads, order and paging
     * @return array{count: int, amount: string, cash_in: string, cash_out: string, charges: string, total: string, pending: array{count: int, amount: string, charges: string, total: string}}
     */
    public function listTotals(Builder $matching): array
    {
        $row = $matching->toBase()
            ->selectRaw('COUNT(*) AS row_count')
            ->selectRaw('COALESCE(SUM(gcash_transactions.amount), 0) AS amount')
            ->selectRaw("COALESCE(SUM(CASE WHEN gcash_transactions.type = 'cash_in' THEN gcash_transactions.amount END), 0) AS cash_in")
            ->selectRaw("COALESCE(SUM(CASE WHEN gcash_transactions.type = 'cash_out' THEN gcash_transactions.amount END), 0) AS cash_out")
            ->selectRaw('COALESCE(SUM(gcash_transactions.charge_amount), 0) AS charges')
            ->selectRaw('COALESCE(SUM(gcash_transactions.total_amount), 0) AS total')
            ->selectRaw("COUNT(CASE WHEN gcash_transactions.status = 'pending' THEN 1 END) AS pending_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN gcash_transactions.status = 'pending' THEN gcash_transactions.amount END), 0) AS pending_amount")
            ->selectRaw("COALESCE(SUM(CASE WHEN gcash_transactions.status = 'pending' THEN gcash_transactions.charge_amount END), 0) AS pending_charges")
            ->selectRaw("COALESCE(SUM(CASE WHEN gcash_transactions.status = 'pending' THEN gcash_transactions.total_amount END), 0) AS pending_total")
            ->first();

        return [
            'count' => (int) $row->row_count,
            'amount' => self::pesos($row->amount),
            'cash_in' => self::pesos($row->cash_in),
            'cash_out' => self::pesos($row->cash_out),
            'charges' => self::pesos($row->charges),
            'total' => self::pesos($row->total),
            'pending' => [
                'count' => (int) $row->pending_count,
                'amount' => self::pesos($row->pending_amount),
                'charges' => self::pesos($row->pending_charges),
                'total' => self::pesos($row->pending_total),
            ],
        ];
    }

    /** A DECIMAL sum as the API's two-decimal peso string ("1500.00"). */
    private static function pesos(string|int|float $sum): string
    {
        return (string) BigDecimal::of((string) $sum)->toScale(2, RoundingMode::HalfUp);
    }

    private function resolveTier(float $amount): GCashTier
    {
        $tier = GCashTier::query()
            ->where('min_amount', '<=', $amount)
            ->where('max_amount', '>=', $amount)
            ->first();

        if (! $tier) {
            throw ValidationException::withMessages([
                'amount' => ["No tier matches amount {$amount}. Check the configured tier ranges."],
            ]);
        }

        return $tier;
    }

    /**
     * Lock the branch a walk-in transaction is recorded in and refuse it,
     * under that lock, when the branch is closed or waiting to close (a
     * pending closure request). StoreGCashTransactionRequest already refused a
     * closed branch with a plain read; this re-check sees a closure approved
     * or requested after it. A suspended branch with no closure request still
     * takes GCash, as BranchTakesNew::openOnly() allows.
     *
     * The transaction's first locking read, on the parent row: BranchStatus
     * locks the branch row before its closure rows, and MemberTransfer the
     * branch before the GCash rows, so all three take the same order.
     *
     * @throws ValidationException 422 on `branch_id`, in words
     */
    private function lockBranchForWalkIn(int $branchId): void
    {
        $branch = Branch::query()->whereKey($branchId)->lockForUpdate()->firstOrFail(['id', 'name', 'code', 'status']);

        if ($branch->status === Branch::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'branch_id' => [BranchWriteGuard::refusal($branch, BranchTakesNew::NOTHING_NEW)],
            ]);
        }

        $closing = BranchClosure::query()
            ->where('branch_id', $branch->id)
            ->where('status', BranchClosure::STATUS_PENDING)
            ->sharedLock()
            ->exists();

        if ($closing) {
            throw ValidationException::withMessages([
                'branch_id' => [sprintf('Branch %s is waiting to close, so %s.', $branch->auditName(), BranchTakesNew::NOTHING_NEW)],
            ]);
        }
    }

    private function generateReferenceNo(Carbon $date): string
    {
        $datePart = $date->format('Ymd');
        $prefix = "GC-{$datePart}-";

        $last = GCashTransaction::query()
            ->where('reference_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('reference_no')
            ->value('reference_no');

        $next = $last
            ? (int) substr($last, strlen($prefix)) + 1
            : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * The same party, type and amount inside a minute is almost always a
     * double-tap at the counter rather than two real transactions.
     *
     * Scoped to whichever party the payload carries. Matching on the party
     * COLUMN rather than its value matters: without it a walk-in's second
     * transaction would be compared against `borrower_id IS NULL`, which every
     * other walk-in's row also satisfies, and unrelated customers would block
     * each other at the window.
     *
     * A locking read, run under the reference lock: it reads the latest
     * committed rows whatever this transaction read before, and holds them
     * until the insert.
     *
     * @param  array{borrower_id?: int|null, gcash_non_member_id?: int|null}  $payload
     */
    private function detectDuplicate(array $payload, string $type, float $amount): ?GCashTransaction
    {
        $borrowerId = $payload['borrower_id'] ?? null;
        $nonMemberId = $payload['gcash_non_member_id'] ?? null;

        return GCashTransaction::query()
            ->when($borrowerId, fn ($q) => $q->where('borrower_id', $borrowerId))
            ->when($nonMemberId, fn ($q) => $q->where('gcash_non_member_id', $nonMemberId))
            ->where('type', $type)
            ->where('amount', $amount)
            ->where('transaction_date', '>=', now()->subSeconds(60))
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }
}
