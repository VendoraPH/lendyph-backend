<?php

namespace App\Services\Savings\AutoCredit;

use App\Models\AccountingJournal;
use App\Models\AccountingJournalLine;
use App\Models\SavingsAutoCreditPosting;
use App\Models\SavingsAutoCreditRun;
use App\Models\SavingsAutoCreditSkip;
use App\Models\SavingsTransaction;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\Savings\Api\AccountScope;
use App\Services\Savings\SavingsMath;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Past savings auto-credit runs (gap round B7), read only.
 *
 * - **Scope:** `branches:view_all` sees every run with its stored figures. A
 *   branch user sees the runs that posted to or skipped an account in their
 *   branches, and every count and total is worked out from those accounts
 *   only. Another run answers 404, as a missing one does.
 * - **Settlement account:** the account the run's deposits debited, read from
 *   the journal of its first credit the caller can see (null when it credited
 *   nothing, or the organisation keeps no books).
 * - **Skipped lists** exist for runs since this change (`skips_recorded`). An
 *   older run keeps its stored skipped count for head office; its list, and a
 *   branch user's count, read null (never stored).
 *
 * Every figure is the server's, in whole centavos, sent as 2-decimal strings.
 */
class RunHistory
{
    /** A run's settlement method in words. */
    public const METHOD_WORDS = ['bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya'];

    public function __construct(
        private readonly BranchAccess $branches,
        private readonly AccountScope $scope,
    ) {}

    /** @return LengthAwarePaginator<int, SavingsAutoCreditRun> */
    public function page(User $user, int $perPage): LengthAwarePaginator
    {
        return $this->visible($user)->with('creator')->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }

    public function find(User $user, int $id): SavingsAutoCreditRun
    {
        return $this->visible($user)->with('creator')->whereKey($id)->firstOr(fn () => abort(404, 'Auto-credit run not found.'));
    }

    /**
     * @param  iterable<SavingsAutoCreditRun>  $runs
     * @return list<array<string, mixed>>
     */
    public function rows(iterable $runs, User $user): array
    {
        $runs = collect($runs);
        $posted = $this->postedTotals($runs->pluck('id')->all(), $user);
        $skips = $this->skipCounts($runs->pluck('id')->all(), $user);
        $settlement = $this->settlementAccounts($posted->map(fn (object $row) => (int) $row->first_transaction_id)->all());
        $all = $this->branches->for($user) === null;

        return $runs->map(function (SavingsAutoCreditRun $run) use ($posted, $skips, $settlement, $all) {
            $mine = $posted->get($run->id);

            return [
                'id' => $run->id,
                'cycle_date' => $run->cycle_date->toDateString(),
                'cycle_label' => Cycle::words($run->cycle_date->toDateString()),
                'posted_on' => $run->posted_on->toDateString(),
                'method' => $run->method,
                'method_label' => self::METHOD_WORDS[$run->method] ?? $run->method,
                'reference_number' => $run->reference_number,
                'settlement_account' => $settlement[$run->id] ?? null,
                'posted_count' => $all ? $run->posted_count : (int) ($mine->posted_count ?? 0),
                'posted_total' => SavingsMath::pesos(SavingsMath::centavos($all ? $run->posted_total : ($mine->posted_total ?? '0.00'))),
                'skipped_count' => match (true) {
                    $all => $run->skipped_count,
                    $run->skips_recorded => (int) ($skips[$run->id] ?? 0),
                    default => null,
                },
                'skips_recorded' => $run->skips_recorded,
                'run_by' => $run->creator === null ? null : ['id' => $run->creator->id, 'name' => $run->creator->full_name],
                'created_at' => $run->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * One run with its posted and skipped lists, limited to the caller's
     * branches.
     *
     * @return array<string, mixed>
     */
    public function detail(SavingsAutoCreditRun $run, User $user): array
    {
        $postings = $this->postings($run->id, $user);
        $reversed = SavingsTransaction::query()->whereIn('reverses_transaction_id', $postings->pluck('transaction_id'))->pluck('reverses_transaction_id')->flip();

        $posted = $postings->map(function (SavingsAutoCreditPosting $posting) use ($reversed) {
            $status = $reversed->has($posting->transaction_id) ? 'reversed' : 'credited';

            return [
                'instruction_id' => $posting->instruction_id,
                'account_id' => $posting->account_id,
                'account_number' => $posting->account?->account_number,
                'borrower_name' => $posting->account?->borrower?->full_name,
                'amount' => SavingsMath::pesos(SavingsMath::centavos($posting->amount)),
                'transaction_id' => $posting->transaction_id,
                'reference' => $posting->transaction?->reference,
                'status' => $status,
                'status_label' => AutoCreditRunner::CREDITED_WORDS[$status],
            ];
        })->values()->all();

        $skipped = $run->skips_recorded
            ? $this->skips($run->id, $user)->map(fn (SavingsAutoCreditSkip $skip) => [
                'instruction_id' => $skip->instruction_id,
                'account_id' => $skip->account_id,
                'account_number' => $skip->account?->account_number,
                'borrower_name' => $skip->account?->borrower?->full_name,
                'amount' => SavingsMath::pesos(SavingsMath::centavos($skip->amount)),
                'reason' => $skip->reason,
            ])->values()->all()
            : null;

        return [...$this->rows([$run], $user)[0], 'posted' => $posted, 'skipped' => $skipped];
    }

    /** @return Builder<SavingsAutoCreditRun> */
    private function visible(User $user): Builder
    {
        if ($this->branches->for($user) === null) {
            return SavingsAutoCreditRun::query();
        }

        return SavingsAutoCreditRun::query()->where(fn (Builder $q) => $q
            ->whereIn('id', SavingsAutoCreditPosting::query()->whereIn('account_id', $this->scope->accountIds($user))->select('run_id'))
            ->orWhereIn('id', SavingsAutoCreditSkip::query()->whereIn('account_id', $this->scope->accountIds($user))->select('run_id')));
    }

    /**
     * One run's credits on the caller's accounts, with their accounts and
     * members (detail() only).
     *
     * @return Collection<int, SavingsAutoCreditPosting>
     */
    private function postings(int $runId, User $user): Collection
    {
        return SavingsAutoCreditPosting::query()
            ->with(['account.borrower', 'transaction:id,reference'])
            ->where('run_id', $runId)
            ->whereIn('account_id', $this->scope->accountIds($user))
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, SavingsAutoCreditSkip> */
    private function skips(int $runId, User $user): Collection
    {
        return SavingsAutoCreditSkip::query()
            ->with('account.borrower')
            ->where('run_id', $runId)
            ->whereIn('account_id', $this->scope->accountIds($user))
            ->orderBy('id')
            ->get();
    }

    /**
     * Each run's credits on the caller's accounts, grouped in SQL: the count,
     * the ₱ sum (an exact decimal) and the first credit's transaction (for the
     * settlement account). No posting, account or member rows are loaded.
     *
     * @param  list<int>  $runIds
     * @return Collection<int, object{run_id: int, posted_count: int, posted_total: string, first_transaction_id: int}> keyed by run id
     */
    private function postedTotals(array $runIds, User $user): Collection
    {
        return SavingsAutoCreditPosting::query()
            ->toBase()
            ->whereIn('run_id', $runIds)
            ->whereIn('account_id', $this->scope->accountIds($user))
            ->groupBy('run_id')
            ->selectRaw('run_id, COUNT(*) AS posted_count, SUM(amount) AS posted_total, MIN(transaction_id) AS first_transaction_id')
            ->get()
            ->keyBy('run_id');
    }

    /**
     * @param  list<int>  $runIds
     * @return Collection<int, int> keyed by run id
     */
    private function skipCounts(array $runIds, User $user): Collection
    {
        return SavingsAutoCreditSkip::query()
            ->whereIn('run_id', $runIds)
            ->whereIn('account_id', $this->scope->accountIds($user))
            ->groupBy('run_id')
            ->selectRaw('run_id, COUNT(*) AS aggregate')
            ->pluck('aggregate', 'run_id');
    }

    /**
     * The account each run's deposits debited: the debit line of the journal
     * of one of its credits.
     *
     * @param  array<int, int>  $transactionByRun  run id => transaction id
     * @return array<int, array{id: int, code: string, name: string}>
     */
    private function settlementAccounts(array $transactionByRun): array
    {
        if ($transactionByRun === []) {
            return [];
        }

        $journals = AccountingJournal::query()
            ->where('postable_type', SavingsTransaction::class)
            ->whereIn('postable_id', array_values($transactionByRun))
            ->where('source', 'savings')
            ->whereNull('reverses_journal_id')
            ->pluck('id', 'postable_id');
        $lines = AccountingJournalLine::query()
            ->with('account:id,code,name')
            ->whereIn('accounting_journal_id', $journals->values())
            ->where('debit', '>', 0)
            ->orderBy('line_no')
            ->get()
            ->unique('accounting_journal_id')
            ->keyBy('accounting_journal_id');

        $accounts = [];

        foreach ($transactionByRun as $runId => $transactionId) {
            $account = $lines->get($journals->get($transactionId))?->account;

            if ($account !== null) {
                $accounts[$runId] = ['id' => $account->id, 'code' => $account->code, 'name' => $account->name];
            }
        }

        return $accounts;
    }
}
