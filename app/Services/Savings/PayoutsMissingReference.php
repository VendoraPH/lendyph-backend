<?php

namespace App\Services\Savings;

use App\Http\Resources\Savings\SavingsUserRef;
use App\Models\Borrower;
use App\Models\SavingsTransaction;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * "Payouts missing a reference" (finish run round 6): the withdrawals (the
 * Member Portal's included), closing payouts and time deposit payouts sent by
 * bank transfer, GCash or Maya that still have no reference and aren't
 * reversed, in the caller's branches, newest first, 25 a page. The Savings
 * dashboard's count is {@see self::count()}, the same query.
 *
 * A row names the member by name and code only (the round fz minimum), never
 * contacts or addresses.
 */
class PayoutsMissingReference
{
    public const PER_PAGE = 25;

    /** The method in words, as the screens read it. */
    public const METHOD_LABELS = [
        'bank_transfer' => 'Bank transfer',
        'gcash' => 'GCash',
        'maya' => 'Maya',
    ];

    /**
     * @param  list<int>|null  $branchIds  the caller's branches, null for all
     * @param  array{branch_id?: int|string|null, date_from?: string|null, date_to?: string|null, method?: string|null}  $filters
     */
    public function query(?array $branchIds, array $filters = []): Builder
    {
        $transactions = DB::table('savings_transactions as t')
            ->join('savings_accounts as a', 'a.id', '=', 't.account_id')
            ->selectRaw("'transaction' AS source, t.id, t.type AS kind, t.date, t.method, t.debit AS amount, a.branch_id, a.borrower_id, a.id AS account_id, a.account_number, NULL AS time_deposit_id, NULL AS certificate_number, t.reference, t.created_by AS posted_by_id")
            ->whereIn('t.type', SavingsTransaction::PAYOUT_REFERENCE_TYPES)
            ->whereNull('t.reverses_transaction_id')
            ->whereIn('t.method', SavingsTransaction::PAYOUT_REFERENCE_METHODS)
            ->whereRaw(SavingsTransaction::blankReferenceSql('t.external_reference'))
            ->whereNotExists(fn (Builder $reversals) => $reversals->selectRaw('1')
                ->from('savings_transactions as r')
                ->whereColumn('r.reverses_transaction_id', 't.id'));
        $this->filter($transactions, 'a.branch_id', 't.date', 't.method', $branchIds, $filters);

        $deposits = DB::table('time_deposits as d')
            ->selectRaw("'time_deposit' AS source, d.id, CASE WHEN d.status = 'preterminated' THEN 'time_deposit_pretermination' ELSE 'time_deposit_maturity' END AS kind, d.settled_on AS date, d.payout_method AS method, d.settled_amount AS amount, d.branch_id, d.borrower_id, NULL AS account_id, NULL AS account_number, d.id AS time_deposit_id, d.certificate_number, d.certificate_number AS reference, d.settled_by AS posted_by_id")
            ->whereIn('d.status', PayoutReferences::SETTLED_STATUSES)
            ->whereIn('d.payout_method', SavingsTransaction::PAYOUT_REFERENCE_METHODS)
            ->where('d.settled_amount', '>', 0)
            ->whereRaw(SavingsTransaction::blankReferenceSql('d.payout_reference'));
        $this->filter($deposits, 'd.branch_id', 'd.settled_on', 'd.payout_method', $branchIds, $filters);

        return DB::query()->fromSub($transactions->unionAll($deposits), 'payouts');
    }

    /** @param  list<int>|null  $branchIds */
    public function count(?array $branchIds, ?int $branchId = null): int
    {
        return $this->query($branchIds, ['branch_id' => $branchId])->count();
    }

    /**
     * One page of rows, each with whether `$user` may record its reference.
     *
     * @param  list<int>|null  $branchIds
     * @param  array<string, mixed>  $filters
     * @return array{page: LengthAwarePaginator<int, object>, rows: list<array<string, mixed>>}
     */
    public function page(User $user, ?array $branchIds, array $filters, int $page): array
    {
        $paginator = $this->query($branchIds, $filters)
            ->orderByDesc('date')
            ->orderByDesc('source')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);
        $items = collect($paginator->items());

        $members = Borrower::query()->whereIn('id', $items->pluck('borrower_id')->unique()->values()->all())
            ->get()->keyBy('id');
        $posters = User::query()->whereIn('id', $items->pluck('posted_by_id')->filter()->unique()->values()->all())->get()->keyBy('id');

        $rows = $items->map(function (object $row) use ($members, $posters, $user): array {
            $member = $members->get($row->borrower_id);

            return [
                'key' => "{$row->source}-{$row->id}",
                'source' => $row->source,
                'id' => (int) $row->id,
                'kind' => $row->kind,
                'kind_label' => PayoutReferences::KIND_WORDS[$row->kind],
                'date' => substr((string) $row->date, 0, 10),
                'member' => $member === null ? null : ['id' => (int) $member->id, 'name' => $member->full_name, 'code' => $member->borrower_code],
                'account_id' => $row->account_id === null ? null : (int) $row->account_id,
                'account_number' => $row->account_number,
                'time_deposit_id' => $row->time_deposit_id === null ? null : (int) $row->time_deposit_id,
                'certificate_number' => $row->certificate_number,
                'reference' => $row->reference,
                'method' => $row->method,
                'method_label' => self::METHOD_LABELS[$row->method],
                'amount' => SavingsMath::pesos(SavingsMath::centavos((string) $row->amount)),
                'posted_by' => SavingsUserRef::from($row->posted_by_id === null ? null : $posters->get($row->posted_by_id)),
                'can_record_reference' => $user->can(PayoutReferences::PERMISSIONS[$row->kind]),
            ];
        })->values()->all();

        return ['page' => $paginator, 'rows' => $rows];
    }

    /**
     * @param  list<int>|null  $branchIds
     * @param  array<string, mixed>  $filters
     */
    private function filter(Builder $query, string $branchColumn, string $dateColumn, string $methodColumn, ?array $branchIds, array $filters): void
    {
        $query
            ->when($branchIds !== null, fn (Builder $q) => $q->whereIn($branchColumn, $branchIds))
            ->when(($filters['branch_id'] ?? null) !== null, fn (Builder $q) => $q->where($branchColumn, (int) $filters['branch_id']))
            ->when(($filters['date_from'] ?? null) !== null, fn (Builder $q) => $q->where($dateColumn, '>=', $filters['date_from']))
            ->when(($filters['date_to'] ?? null) !== null, fn (Builder $q) => $q->where($dateColumn, '<=', $filters['date_to']))
            ->when(($filters['method'] ?? null) !== null, fn (Builder $q) => $q->where($methodColumn, $filters['method']));
    }
}
