<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\GCashTransaction;
use App\Models\Loan;
use App\Models\LoanAdjustment;
use App\Models\Repayment;
use App\Models\ShareCapitalLedger;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * The past events the books never heard of, or heard of differently from
 * today's rules (Phase 2, Accounting decision 13). Nothing is back-posted:
 * this only lists them, so an accountant can decide what to do with each.
 *
 * ## What it lists
 *
 * One row per event, each kind read from its own lending table:
 *
 * - `release`: an ever-released loan with no `loan_release` journal;
 * - `collection`: a posted (not voided) payment with no `loan_collection`
 *   journal. A voided one that posted nothing moved no money on the books;
 * - `build_up`: a payment whose collection journal holds its share capital
 *   build-up as an overpayment instead of crediting share capital (no
 *   {@see self::BUILD_UP_LINE} line);
 * - `restructure`: a loan a restructure created whose release journal has
 *   neither the carried principal nor the capitalised line, so it posted as
 *   an ordinary release;
 * - `adjustment`: an applied balance adjustment with no `adjustment` journal;
 * - `share_capital`: a share capital entry of its own (manual, bulk or
 *   auto-credit; not a payment's build-up, not a release's withholding) with
 *   no `share_capital` journal;
 * - `cash_in` / `cash_out`: a paid Cash In or a completed Cash Out with no
 *   journal of that source.
 *
 * A journal counts when it is posted or reversed (real history); a draft
 * does not. An event listed under one kind is never listed under another:
 * a payment with no journal at all is a `collection`, never a `build_up`.
 *
 * ## The figures
 *
 * `amount` is the event's own size, in centavos summed exactly in SQL: a
 * loan's principal, a payment's amount paid, the build-up credited, an
 * adjustment's amount (either direction), a share capital entry's credit or
 * debit, a GCash transaction's amount. The reason says which way it went.
 *
 * ## It only ever reads
 *
 * Every statement is a SELECT, so the command and the endpoint are safe on
 * any deployment. Branch scope is the caller's: `int|list<int>|null`, as
 * the other accounting reports take it (null is every branch, rows with no
 * branch included; a list leaves rows with no branch out).
 */
final class LedgerGapsReport
{
    public const KINDS = ['release', 'collection', 'build_up', 'restructure', 'adjustment', 'share_capital', 'cash_in', 'cash_out'];

    /** What each kind is called on screen and in the command. */
    public const KIND_LABELS = [
        'release' => 'Release',
        'collection' => 'Collection',
        'build_up' => 'Build-up',
        'restructure' => 'Restructure',
        'adjustment' => 'Balance adjustment',
        'share_capital' => 'Share capital entry',
        'cash_in' => 'Cash In',
        'cash_out' => 'Cash Out',
    ];

    public const PER_PAGE = 25;

    /**
     * The line PostingRules::loanCollection() credits a build-up on. A
     * collection journal without it held the build-up as an overpayment.
     */
    public const BUILD_UP_LINE = 'Share capital build-up';

    /** The lines PostingRules::restructureRelease() marks J1 with. */
    public const RESTRUCTURE_LINE_PREFIXES = ['Principal carried from ', 'Interest and penalty capitalised from '];

    public const NO_BOOKS_NOTICE = 'No chart of accounts: this organisation keeps no books, so nothing was ever posted and nothing is listed.';

    /** ShareCapitalService::processAutoCredit()'s description. */
    private const AUTO_CREDIT_DESCRIPTION = 'Monthly pledge - auto-credit';

    public function booksKept(): bool
    {
        return AccountMap::chartExists();
    }

    /**
     * One page of rows, ordered by date, then kind, then record.
     *
     * @param  array{kind?: string|null, from?: string|null, to?: string|null}  $filters
     * @param  int|list<int>|null  $branches
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function page(array $filters, int|array|null $branches, int $page, int $perPage = self::PER_PAGE): array
    {
        if (! $this->booksKept()) {
            return ['rows' => [], 'total' => 0];
        }

        $query = $this->query($filters, $branches);
        $total = (int) (clone $query)->count();
        $rows = $this->ordered($query)->forPage($page, $perPage)->get();

        return ['rows' => $this->present($rows), 'total' => $total];
    }

    /**
     * Every row, read in chunks, for the command.
     *
     * @param  array{kind?: string|null, from?: string|null, to?: string|null}  $filters
     * @param  int|list<int>|null  $branches
     * @return LazyCollection<int, array<string, mixed>>
     */
    public function rows(array $filters, int|array|null $branches = null, int $chunk = 500): LazyCollection
    {
        if (! $this->booksKept()) {
            return LazyCollection::empty();
        }

        return LazyCollection::make(function () use ($filters, $branches, $chunk) {
            for ($page = 1; ; $page++) {
                $rows = $this->ordered($this->query($filters, $branches))->forPage($page, $chunk)->get();

                yield from $this->present($rows);

                if ($rows->count() < $chunk) {
                    return;
                }
            }
        });
    }

    /**
     * The count and amount of every kind, and the overall count. Every kind
     * is present, ₱0.00 where nothing is missing.
     *
     * @param  array{kind?: string|null, from?: string|null, to?: string|null}  $filters
     * @param  int|list<int>|null  $branches
     * @return array{count: int, by_kind: array<string, array{count: int, amount: string}>}
     */
    public function totals(array $filters, int|array|null $branches): array
    {
        $found = $this->booksKept()
            ? DB::query()
                ->fromSub($this->query($filters, $branches), 'gaps')
                ->groupBy('kind')
                ->selectRaw('kind, COUNT(*) AS events, COALESCE(SUM(amount), 0) AS amount')
                ->get()
                ->keyBy('kind')
            : collect();

        $byKind = [];

        foreach (self::KINDS as $kind) {
            $row = $found->get($kind);
            $byKind[$kind] = [
                'count' => (int) ($row->events ?? 0),
                'amount' => self::pesos((int) ($row->amount ?? 0)),
            ];
        }

        return [
            'count' => array_sum(array_column($byKind, 'count')),
            'by_kind' => $byKind,
        ];
    }

    /** Centavos as the two-decimal peso string the API sends ("1500.00"). */
    public static function pesos(int $centavos): string
    {
        $sign = $centavos < 0 ? '-' : '';
        $centavos = abs($centavos);

        return sprintf('%s%d.%02d', $sign, intdiv($centavos, 100), $centavos % 100);
    }

    /**
     * The union of every kind asked for, filtered by date and branch, as
     * `kind, record_id, reference, event_date, branch_id, borrower_id,
     * amount (centavos), posted, detail`.
     *
     * @param  array{kind?: string|null, from?: string|null, to?: string|null}  $filters
     * @param  int|list<int>|null  $branches
     */
    private function query(array $filters, int|array|null $branches): Builder
    {
        $kinds = filled($filters['kind'] ?? null) ? [$filters['kind']] : self::KINDS;
        $union = null;

        foreach ($kinds as $kind) {
            $select = match ($kind) {
                'release' => $this->selectRelease(),
                'collection' => $this->selectCollection(),
                'build_up' => $this->selectBuildUp(),
                'restructure' => $this->selectRestructure(),
                'adjustment' => $this->selectAdjustment(),
                'share_capital' => $this->selectShareCapital(),
                'cash_in' => $this->selectGCash('cash_in', 'paid', 'COALESCE(g.paid_at, g.transaction_date)'),
                'cash_out' => $this->selectGCash('cash_out', 'completed', 'g.transaction_date'),
            };
            $union = $union === null ? $select : $union->unionAll($select);
        }

        return DB::query()
            ->fromSub($union, 'gaps')
            ->when(filled($filters['from'] ?? null), fn (Builder $q) => $q->where('event_date', '>=', $filters['from']))
            ->when(filled($filters['to'] ?? null), fn (Builder $q) => $q->where('event_date', '<=', $filters['to']))
            ->when(is_int($branches), fn (Builder $q) => $q->where('branch_id', $branches))
            ->when(is_array($branches), fn (Builder $q) => $q->whereIn('branch_id', $branches));
    }

    private function ordered(Builder $query): Builder
    {
        return $query->select('gaps.*')->orderBy('event_date')->orderBy('kind')->orderBy('record_id');
    }

    private function selectRelease(): Builder
    {
        return DB::table('loans as l')
            ->whereIn('l.status', Loan::EVER_RELEASED_STATUSES)
            ->whereNotExists($this->journal(Loan::class, 'l.id', 'loan_release'))
            ->selectRaw("'release' AS kind, l.id AS record_id, COALESCE(l.loan_account_number, l.application_number) AS reference")
            ->selectRaw('DATE(l.released_at) AS event_date, l.branch_id, l.borrower_id')
            ->selectRaw('CAST(ROUND(l.principal_amount * 100) AS SIGNED) AS amount')
            ->selectRaw("'none' AS posted")
            ->selectRaw("CASE WHEN l.external_loan_no IS NOT NULL OR l.imported_arrears_baseline IS NOT NULL THEN 'imported'
                WHEN l.source_loan_id IS NOT NULL THEN 'restructure' ELSE NULL END AS detail");
    }

    private function selectCollection(): Builder
    {
        return DB::table('repayments as r')
            ->join('loans as l', 'l.id', '=', 'r.loan_id')
            ->where('r.status', 'posted')
            ->whereNotExists($this->journal(Repayment::class, 'r.id', 'loan_collection'))
            ->selectRaw("'collection' AS kind, r.id AS record_id, r.receipt_number AS reference")
            ->selectRaw('r.payment_date AS event_date, l.branch_id, l.borrower_id')
            ->selectRaw('CAST(ROUND(r.amount_paid * 100) AS SIGNED) AS amount')
            ->selectRaw("'none' AS posted, NULL AS detail");
    }

    private function selectBuildUp(): Builder
    {
        $credited = DB::table('share_capital_ledger')
            ->whereNotNull('repayment_id')
            ->where('credit', '>', 0)
            ->groupBy('repayment_id')
            ->selectRaw('repayment_id, SUM(credit) AS credited');

        return DB::table('repayments as r')
            ->join('loans as l', 'l.id', '=', 'r.loan_id')
            ->joinSub($credited, 'b', 'b.repayment_id', '=', 'r.id')
            ->where('r.status', 'posted')
            ->whereExists($this->journal(Repayment::class, 'r.id', 'loan_collection'))
            ->whereNotExists($this->journal(Repayment::class, 'r.id', 'loan_collection')
                ->join('accounting_journal_lines as jl', 'jl.accounting_journal_id', '=', 'j.id')
                ->where('jl.description', self::BUILD_UP_LINE)
                ->where('jl.credit', '>', 0))
            ->selectRaw("'build_up' AS kind, r.id AS record_id, r.receipt_number AS reference")
            ->selectRaw('r.payment_date AS event_date, l.branch_id, l.borrower_id')
            ->selectRaw('CAST(ROUND(b.credited * 100) AS SIGNED) AS amount')
            ->selectRaw("'differently' AS posted, NULL AS detail");
    }

    private function selectRestructure(): Builder
    {
        return DB::table('loans as l')
            ->whereNotNull('l.source_loan_id')
            ->whereIn('l.status', Loan::EVER_RELEASED_STATUSES)
            ->whereExists($this->journal(Loan::class, 'l.id', 'loan_release'))
            ->whereNotExists($this->journal(Loan::class, 'l.id', 'loan_release')
                ->join('accounting_journal_lines as jl', 'jl.accounting_journal_id', '=', 'j.id')
                ->where(function (Builder $lines): void {
                    foreach (self::RESTRUCTURE_LINE_PREFIXES as $prefix) {
                        $lines->orWhere('jl.description', 'like', $prefix.'%');
                    }
                }))
            ->selectRaw("'restructure' AS kind, l.id AS record_id, COALESCE(l.loan_account_number, l.application_number) AS reference")
            ->selectRaw('DATE(l.released_at) AS event_date, l.branch_id, l.borrower_id')
            ->selectRaw('CAST(ROUND(l.principal_amount * 100) AS SIGNED) AS amount')
            ->selectRaw("'differently' AS posted, NULL AS detail");
    }

    private function selectAdjustment(): Builder
    {
        $stated = "CAST(JSON_UNQUOTE(JSON_EXTRACT(a.new_values, '$.adjustment_amount')) AS DECIMAL(15,2))";

        return DB::table('loan_adjustments as a')
            ->join('loans as l', 'l.id', '=', 'a.loan_id')
            ->where('a.adjustment_type', 'balance_adjustment')
            ->where('a.status', 'applied')
            ->whereNotExists($this->journal(LoanAdjustment::class, 'a.id', 'adjustment'))
            ->selectRaw("'adjustment' AS kind, a.id AS record_id, a.adjustment_number AS reference")
            ->selectRaw('DATE(a.applied_at) AS event_date, l.branch_id, l.borrower_id')
            ->selectRaw("CAST(ROUND(ABS(COALESCE({$stated}, 0)) * 100) AS SIGNED) AS amount")
            ->selectRaw("'none' AS posted")
            ->selectRaw("CASE WHEN COALESCE({$stated}, 0) < 0 THEN 'reduction' WHEN COALESCE({$stated}, 0) > 0 THEN 'increase' ELSE NULL END AS detail");
    }

    private function selectShareCapital(): Builder
    {
        return DB::table('share_capital_ledger as s')
            ->leftJoin('borrowers as b', 'b.id', '=', 's.borrower_id')
            ->whereNull('s.repayment_id')
            ->whereNull('s.loan_id')
            ->whereRaw('(COALESCE(s.credit, 0) + COALESCE(s.debit, 0)) > 0')
            ->whereNotExists($this->journal(ShareCapitalLedger::class, 's.id', 'share_capital'))
            ->selectRaw("'share_capital' AS kind, s.id AS record_id, s.reference")
            ->selectRaw('s.date AS event_date, b.branch_id, s.borrower_id')
            ->selectRaw('CAST(ROUND((COALESCE(s.credit, 0) + COALESCE(s.debit, 0)) * 100) AS SIGNED) AS amount')
            ->selectRaw("'none' AS posted")
            ->selectRaw("CASE WHEN COALESCE(s.debit, 0) > 0 THEN 'withdrawal' WHEN s.description = ? THEN 'auto_credit' ELSE 'contribution' END AS detail", [self::AUTO_CREDIT_DESCRIPTION]);
    }

    private function selectGCash(string $type, string $status, string $dateColumn): Builder
    {
        return DB::table('gcash_transactions as g')
            ->leftJoin('borrowers as b', 'b.id', '=', 'g.borrower_id')
            ->where('g.type', $type)
            ->where('g.status', $status)
            ->whereNotExists($this->journal(GCashTransaction::class, 'g.id', $type))
            ->selectRaw('? AS kind, g.id AS record_id, g.reference_no AS reference', [$type])
            ->selectRaw("DATE({$dateColumn}) AS event_date, b.branch_id, g.borrower_id")
            ->selectRaw('CAST(ROUND(g.amount * 100) AS SIGNED) AS amount')
            ->selectRaw("'none' AS posted")
            ->selectRaw("CASE WHEN g.borrower_id IS NULL THEN 'walk_in' ELSE NULL END AS detail");
    }

    /** The real-history journals of `$source` keyed on the document in `$idColumn`. */
    private function journal(string $postableType, string $idColumn, string $source): Builder
    {
        return DB::table('accounting_journals as j')
            ->selectRaw('1')
            ->where('j.postable_type', $postableType)
            ->whereColumn('j.postable_id', $idColumn)
            ->where('j.source', $source)
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES);
    }

    /**
     * Rows as the API sends them: the member by name and code only, the
     * branch by name, the amount in pesos with two decimals, and the reason.
     *
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function present(Collection $rows): array
    {
        $members = Borrower::query()
            ->whereIn('id', $rows->pluck('borrower_id')->filter()->unique()->all())
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'borrower_code'])
            ->keyBy('id');
        $branches = Branch::query()
            ->whereIn('id', $rows->pluck('branch_id')->filter()->unique()->all())
            ->pluck('name', 'id');
        $accounts = $rows->contains('kind', 'build_up') ? $this->buildUpAccounts() : ['held' => null, 'equity' => null];

        return $rows->map(function (object $row) use ($members, $branches, $accounts): array {
            $member = $row->borrower_id === null ? null : $members->get((int) $row->borrower_id);
            $branchId = $row->branch_id === null ? null : (int) $row->branch_id;

            return [
                'kind' => $row->kind,
                'kind_label' => self::KIND_LABELS[$row->kind],
                'record_id' => (int) $row->record_id,
                'reference' => $row->reference,
                'date' => $row->event_date === null ? null : substr((string) $row->event_date, 0, 10),
                'branch' => $branchId === null ? null : ['id' => $branchId, 'name' => $branches->get($branchId)],
                'member' => $member === null ? null : ['name' => $member->full_name, 'code' => $member->borrower_code],
                'amount' => self::pesos((int) $row->amount),
                'posted' => $row->posted,
                'reason' => $this->reason($row->kind, $row->detail, $accounts),
            ];
        })->values()->all();
    }

    /** @param  array{held: string|null, equity: string|null}  $accounts */
    private function reason(string $kind, ?string $detail, array $accounts): string
    {
        return match ($kind) {
            'release' => match ($detail) {
                'imported' => 'Imported loan, released under the old books: no loan release journal.',
                'restructure' => 'Restructure released with no loan release journal.',
                default => 'Released with no loan release journal.',
            },
            'collection' => 'Payment with no collection journal.',
            'build_up' => sprintf(
                'Build-up held as an overpayment on %s instead of credited to %s.',
                $accounts['held'] ?? '2300 Other Liabilities',
                $accounts['equity'] ?? '3060 Share Capital',
            ),
            'restructure' => 'Restructure posted as an ordinary release: no carried principal or capitalised interest and penalty line.',
            'adjustment' => match ($detail) {
                'reduction' => 'Applied balance reduction with no adjustment journal.',
                'increase' => 'Applied balance increase with no adjustment journal.',
                default => 'Applied balance adjustment with no adjustment journal.',
            },
            'share_capital' => match ($detail) {
                'withdrawal' => 'Share capital withdrawal with no journal.',
                'auto_credit' => 'Auto-credit contribution with no journal.',
                default => 'Share capital contribution with no journal.',
            },
            'cash_in' => 'Paid Cash In with no journal.'.($detail === 'walk_in' ? ' Walk-in.' : ''),
            'cash_out' => 'Cash Out with no journal.'.($detail === 'walk_in' ? ' Walk-in.' : ''),
        };
    }

    /**
     * The accounts a build-up was held on and belongs on, as today's
     * mapping names them ("2300 Other Liabilities").
     *
     * @return array{held: string|null, equity: string|null}
     */
    private function buildUpAccounts(): array
    {
        $map = AccountMap::resolve();
        $label = function (string $role) use ($map): ?string {
            if (! $map->has($role)) {
                return null;
            }

            $account = AccountingAccount::query()->find($map->accountFor($role), ['code', 'name']);

            return $account === null ? null : "{$account->code} {$account->name}";
        };

        return ['held' => $label(PostingRules::BORROWER_ADVANCES), 'equity' => $label('share_capital')];
    }
}
