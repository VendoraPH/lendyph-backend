<?php

namespace App\Services\Savings\Api;

use App\Http\Resources\Savings\SavingsUserRef;
use App\Models\SavingsAccount;
use App\Models\SavingsApproval;
use App\Models\SavingsHold;
use App\Models\SavingsTransaction;
use App\Services\Savings\AccruedInterest;
use App\Services\Savings\AvailableBalance;
use App\Services\Savings\SavingsMath;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * The savings screens' shapes (frontend `src/types/savings.ts`) for accounts,
 * transactions, holds and approvals. Money is a two-decimal string, users are
 * `{id, name}` (null for the system), and every figure is the server's:
 * balances and the available balance from AvailableBalance, accrued interest
 * from AccruedInterest. Lists are loaded in a fixed number of queries.
 */
class Presenter
{
    public function __construct(
        private readonly AvailableBalance $available,
        private readonly AccruedInterest $accrued,
    ) {}

    /** @return array<string, mixed> */
    public function account(SavingsAccount $account): array
    {
        return $this->accounts(collect([$account]))[0];
    }

    /**
     * @param  Collection<int, SavingsAccount>  $accounts
     * @return list<array<string, mixed>>
     */
    public function accounts(Collection $accounts): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        $accounts = $this->models($accounts);
        $accounts->loadMissing(['borrower', 'branch:id,name', 'product:id,code,name,kind']);
        $breakdowns = $this->available->forManyWithRequests($accounts, [SavingsHold::TYPE_MEMBER_REQUEST]);
        $open = $accounts->reject(fn (SavingsAccount $a) => $a->isClosed())->values();
        $accrued = $open->isEmpty() ? [] : $this->accrued->forMany($open);

        return $accounts->map(fn (SavingsAccount $a) => [
            'id' => $a->id,
            'account_number' => $a->account_number,
            'borrower_id' => $a->borrower_id,
            'borrower_name' => $a->borrower?->full_name,
            'borrower_code' => $a->borrower?->borrower_code,
            'branch_id' => $a->branch_id,
            'branch_name' => $a->branch?->name,
            'product' => $a->product === null ? null : [
                'id' => $a->product->id, 'code' => $a->product->code, 'name' => $a->product->name, 'kind' => $a->product->kind,
            ],
            'status' => $a->status,
            'balance' => SavingsMath::pesos($breakdowns[$a->id]['ledger']),
            'hold_amount' => SavingsMath::pesos($breakdowns[$a->id]['holds']),
            // Of hold_amount, what members' portal withdrawal requests hold (the member sees it as "Pending withdrawal").
            'held_for_requests' => SavingsMath::pesos($breakdowns[$a->id]['holds'] - $breakdowns[$a->id]['held']),
            'pending_amount' => SavingsMath::pesos($breakdowns[$a->id]['pending']),
            'available_balance' => $a->isClosed() ? '0.00' : SavingsMath::pesos($breakdowns[$a->id]['available']),
            'accrued_interest' => SavingsMath::pesos($accrued[$a->id]['gross'] ?? 0),
            'interest_credited_through' => $this->day($a->interest_credited_through),
            'opened_on' => $this->day($a->opened_on),
            'last_member_activity_on' => $this->day($a->last_member_activity_on),
            'dormant_since' => $this->day($a->dormant_since),
            'closed_on' => $this->day($a->closed_on),
            'close_reason' => $a->close_reason,
            'remarks' => $a->remarks,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    public function transaction(SavingsTransaction $row): array
    {
        return $this->transactions(collect([$row]))[0];
    }

    /**
     * Rows with what the screens need beside the columns: the account number
     * and member, who posted it, the other leg of a transfer (both ways), the
     * reversal that reversed it, and a pending reversal request.
     *
     * @param  Collection<int, SavingsTransaction>  $rows
     * @return list<array<string, mixed>>
     */
    public function transactions(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $rows = $this->models($rows);
        $rows->loadMissing(['account:id,account_number,borrower_id', 'account.borrower', 'creator']);
        $ids = $rows->pluck('id')->all();

        $reversedBy = SavingsTransaction::query()->whereIn('reverses_transaction_id', $ids)->pluck('id', 'reverses_transaction_id');
        $pointedAt = SavingsTransaction::query()->whereIn('counterpart_transaction_id', $ids)->pluck('id', 'counterpart_transaction_id');
        $counterpart = fn (SavingsTransaction $r): ?int => $r->counterpart_transaction_id ?? $pointedAt[$r->id] ?? null;
        $anchors = $rows->mapWithKeys(fn (SavingsTransaction $r) => [$r->id => Reverser::anchorIdOf($r, $counterpart($r))]);
        $pending = SavingsApproval::query()
            ->where('kind', 'reversal')->where('status', 'pending')->where('expires_at', '>', now())
            ->whereIn('target_transaction_id', $anchors->values()->unique()->all())
            ->pluck('id', 'target_transaction_id');

        return $rows->map(fn (SavingsTransaction $r) => [
            'id' => $r->id,
            'account_id' => $r->account_id,
            'account_number' => $r->account?->account_number,
            'borrower_name' => $r->account?->borrower?->full_name,
            'reference' => $r->reference,
            'type' => $r->type,
            'date' => $this->day($r->date),
            'value_date' => $this->day($r->value_date),
            'debit' => SavingsMath::pesos(SavingsMath::centavos($r->debit)),
            'credit' => SavingsMath::pesos(SavingsMath::centavos($r->credit)),
            'balance_after' => SavingsMath::pesos(SavingsMath::signedCentavos($r->balance_after)),
            'method' => $r->method,
            'external_reference' => $r->external_reference,
            'remarks' => $r->remarks,
            'counterpart_transaction_id' => $counterpart($r),
            'reverses_transaction_id' => $r->reverses_transaction_id,
            'reversed_by_transaction_id' => $reversedBy[$r->id] ?? null,
            'pending_reversal_approval_id' => $pending[$anchors[$r->id]] ?? null,
            'time_deposit_id' => $r->time_deposit_id,
            'interest_run_id' => $r->interest_run_id,
            'approval_id' => $r->approval_id,
            'created_by' => SavingsUserRef::from($r->creator),
            'created_at' => $r->created_at?->toJSON(),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    public function hold(SavingsHold $hold): array
    {
        return $this->holds(collect([$hold]))[0];
    }

    /**
     * @param  Collection<int, SavingsHold>  $holds
     * @return list<array<string, mixed>>
     */
    public function holds(Collection $holds): array
    {
        $holds = $this->models($holds);
        $holds->loadMissing(['creator', 'releaser']);

        return $holds->map(fn (SavingsHold $h) => [
            'id' => $h->id,
            'account_id' => $h->account_id,
            'type' => $h->type,
            'amount' => SavingsMath::pesos(SavingsMath::centavos($h->amount)),
            'reason' => $h->reason,
            'placed_on' => $this->day($h->placed_on),
            'expires_on' => $this->day($h->expires_on),
            'status' => $h->status,
            'released_on' => $this->day($h->released_on),
            'release_reason' => $h->release_reason,
            'created_by' => SavingsUserRef::from($h->creator),
            'released_by' => SavingsUserRef::from($h->releaser),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    public function approval(SavingsApproval $approval): array
    {
        return $this->approvals(collect([$approval]))[0];
    }

    /**
     * @param  Collection<int, SavingsApproval>  $approvals
     * @return list<array<string, mixed>>
     */
    public function approvals(Collection $approvals): array
    {
        $approvals = $this->models($approvals);
        $approvals->loadMissing([
            'account:id,account_number,borrower_id,branch_id', 'account.borrower', 'account.branch:id,name',
            'timeDeposit:id,certificate_number,borrower_id,branch_id', 'timeDeposit.borrower', 'timeDeposit.branch:id,name',
            'targetTransaction:id,reference', 'requester', 'firstChecker', 'decider',
        ]);

        return $approvals->map(function (SavingsApproval $a): array {
            $holder = $a->account ?? $a->timeDeposit;

            return [
                'id' => $a->id,
                'kind' => $a->kind,
                'status' => $a->status,
                'account_id' => $a->account_id,
                'account_number' => $a->account?->account_number,
                'time_deposit_id' => $a->time_deposit_id,
                'certificate_number' => $a->timeDeposit?->certificate_number,
                'borrower_name' => $holder?->borrower?->full_name,
                'branch_name' => $holder?->branch?->name,
                'target_transaction_id' => $a->target_transaction_id,
                'target_reference' => $a->targetTransaction?->reference,
                'amount' => SavingsMath::pesos(SavingsMath::centavos($a->amount)),
                'required_checkers' => $a->required_checkers,
                'requested_by' => SavingsUserRef::from($a->requester),
                'first_checker' => SavingsUserRef::from($a->firstChecker),
                'decided_by' => SavingsUserRef::from($a->decider),
                'decision_reason' => $a->decision_reason,
                'decided_at' => $a->decided_at?->toJSON(),
                'expires_at' => $a->expires_at?->toJSON(),
                'result_transaction_id' => $a->result_transaction_id,
                'created_at' => $a->created_at?->toJSON(),
            ];
        })->values()->all();
    }

    /**
     * A list page in the `{success, data, meta}` envelope, with the paginator's
     * figures and any extra meta (`stats`, `totals`).
     *
     * @param  LengthAwarePaginator<int, mixed>  $page
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $meta
     */
    public static function page(LengthAwarePaginator $page, array $rows, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                ...$meta,
            ],
        ]);
    }

    /** `per_page` as the lists take it: 15 by default, at most 100. */
    public static function perPage(mixed $value): int
    {
        return min(max((int) ($value ?? 15), 1), 100);
    }

    /**
     * Counts per status over a query, every status present (0 when none).
     *
     * @param  Builder<covariant Model>  $query
     * @param  list<string>  $statuses
     * @return array<string, int>
     */
    public static function stats(Builder $query, string $column, array $statuses): array
    {
        $counts = $query->toBase()->reorder()->selectRaw("{$column} AS status_key, COUNT(*) AS total")->groupBy($column)->pluck('total', 'status_key');

        return collect($statuses)->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)])->all();
    }

    /**
     * The rows as an Eloquent collection, so their relations load in one query each.
     *
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $rows
     * @return EloquentCollection<int, TModel>
     */
    private function models(Collection $rows): EloquentCollection
    {
        return $rows instanceof EloquentCollection ? $rows : new EloquentCollection($rows->values()->all());
    }

    private function day(?CarbonInterface $date): ?string
    {
        return $date?->toDateString();
    }
}
