<?php

namespace App\Services\Accounting;

use App\Models\AccountingJournal;
use App\Models\Branch;
use App\Services\ReportService;
use Illuminate\Support\Facades\DB;

/**
 * What the allowance for credit losses should be, by branch and bucket
 * (decision 12, Lead's call 7), and what 1200 holds. Reads only.
 *
 * - The base is each past-due loan's outstanding principal, in the bucket of
 *   its oldest unpaid instalment: {@see LoanAgingBuilder::rows()}, days from
 *   the bare due date (the aging rule, no grace), every row but `current`.
 *   The Provisioning report's `principal_basis` reads the same rows, so the
 *   posting and the report count the same loans.
 * - The rates are {@see ReportService::PROVISION_RATES}, the one source;
 *   91–120 and over 120 days both take its `over_90` rate. They are used
 *   exactly, as reduced fractions of integers, and each bucket's required
 *   amount is rounded half up to the centavo once, with no rate too precise
 *   to work out ({@see self::requiredAt()}). A branch's required amount is
 *   the sum of its buckets.
 * - Money is integer centavos throughout.
 */
final class AllowanceCalculator
{
    /** The aging buckets the allowance provides for: every one but `current`. */
    public const BUCKETS = ['1_30', '31_60', '61_90', '91_120', 'over_120'];

    /** The PROVISION_RATES key each bucket takes. */
    private const RATE_KEYS = [
        '1_30' => '1_30',
        '31_60' => '31_60',
        '61_90' => '61_90',
        '91_120' => 'over_90',
        'over_120' => 'over_90',
    ];

    /** The aging screens' words for each bucket. */
    private const LABELS = [
        '1_30' => '1–30 days',
        '31_60' => '31–60 days',
        '61_90' => '61–90 days',
        '91_120' => '91–120 days',
        'over_120' => 'Over 120 days',
    ];

    /** The Provisioning report's `principal_basis.basis_note`. */
    public const BASIS_NOTE = 'Outstanding principal of each loan, in the bucket of its oldest unpaid instalment';

    public function __construct(private LoanAgingBuilder $aging) {}

    /**
     * The rate of each bucket, as the preview's `rates` lists them.
     *
     * @return list<array{bucket: string, label: string, rate_percent: string}>
     */
    public function rates(): array
    {
        return array_map(fn (string $bucket): array => [
            'bucket' => $bucket,
            'label' => self::LABELS[$bucket],
            'rate_percent' => self::percent(self::RATE_KEYS[$bucket]),
        ], self::BUCKETS);
    }

    /**
     * The required allowance on `as_of`, by branch and bucket.
     *
     * Branches: every active one, plus any inactive one with a past-due loan,
     * so the rows add up to the totals; with `$branchIds`, only those. In id
     * order, each with all five buckets, zeros where there's nothing.
     *
     * @param  list<int>|null  $branchIds  null: every branch
     * @return array{as_of: string, branches: list<array{branch: array{id: int, name: string}, buckets: list<array{bucket: string, loan_count: int, principal: int, rate_percent: string, required: int}>, principal: int, required: int}>, totals: array{loan_count: int, principal: int, required: int}}
     */
    public function forDate(string $asOf, ?array $branchIds = null): array
    {
        $grouped = [];

        foreach ($this->aging->rows($asOf, $branchIds) as $row) {
            if (! isset(self::RATE_KEYS[$row['bucket']])) {
                continue;
            }

            $branchId = (int) $row['branch']['id'];
            $grouped[$branchId][$row['bucket']]['loan_count'] = ($grouped[$branchId][$row['bucket']]['loan_count'] ?? 0) + 1;
            $grouped[$branchId][$row['bucket']]['principal'] = ($grouped[$branchId][$row['bucket']]['principal'] ?? 0) + (int) $row['outstanding_principal'];
        }

        $branches = Branch::query()
            ->when(
                $branchIds !== null,
                fn ($query) => $query->whereIn('id', $branchIds),
                fn ($query) => $query->where(fn ($listed) => $listed->where('is_active', true)->orWhereIn('id', array_keys($grouped))),
            )
            ->orderBy('id')
            ->get(['id', 'name']);

        $rows = $branches->map(fn (Branch $branch): array => $this->branchRow($branch, $grouped[$branch->id] ?? []))->values()->all();

        return [
            'as_of' => $asOf,
            'branches' => $rows,
            'totals' => [
                'loan_count' => array_sum(array_map(fn (array $row): int => array_sum(array_column($row['buckets'], 'loan_count')), $rows)),
                'principal' => array_sum(array_column($rows, 'principal')),
                'required' => array_sum(array_column($rows, 'required')),
            ],
        ];
    }

    /**
     * Each branch's 1200 balance on `as_of`, as a positive credit: the
     * posted and reversed journals dated on or before it, on the account
     * `allowance_credit_losses` maps to, credit − debit. `unassigned` is the
     * balance on journals with no branch, and `unassigned_latest_after` the
     * date of the latest no-branch journal with a 1200 line dated after
     * `as_of` (null when there is none), whatever its balance. Zeros and null
     * when there are no books or no allowance account.
     *
     * Without `$lock` (a preview) it is one plain read. With it, the caller
     * already holds the allowance account row lock, and the balance comes
     * from locking reads, as {@see AutomaticPoster::allowanceBalance()} reads
     * it: the allowance's lines by their account index, then their journals
     * by primary key, FOR UPDATE. Under REPEATABLE READ a plain read answers
     * from the snapshot this transaction's first plain read took, which may
     * predate the lock and miss a write-off committed since; a locking read
     * answers from the latest committed rows.
     *
     * @return array{branches: array<int, int>, unassigned: int, unassigned_latest_after: string|null}
     */
    public function balances(string $asOf, bool $lock = false): array
    {
        $none = ['branches' => [], 'unassigned' => 0, 'unassigned_latest_after' => null];

        if (! AccountMap::chartExists()) {
            return $none;
        }

        $map = AccountMap::resolve();

        if (! $map->has('allowance_credit_losses')) {
            return $none;
        }

        $accountId = $map->accountFor('allowance_credit_losses');
        $byJournal = $lock ? $this->lockedBalances($accountId) : $this->plainBalances($accountId);

        $branches = [];
        $unassigned = 0;
        $unassignedLatestAfter = null;

        foreach ($byJournal as [$branchId, $balance, $date]) {
            if ($date > $asOf) {
                if ($branchId === null && ($unassignedLatestAfter === null || $date > $unassignedLatestAfter)) {
                    $unassignedLatestAfter = $date;
                }

                continue;
            }

            if ($branchId === null) {
                $unassigned += $balance;
            } else {
                $branches[$branchId] = ($branches[$branchId] ?? 0) + $balance;
            }
        }

        ksort($branches);

        return ['branches' => $branches, 'unassigned' => $unassigned, 'unassigned_latest_after' => $unassignedLatestAfter];
    }

    /**
     * The required figures beside each branch's 1200 balance: `adjustment`
     * = `required` − `current_balance`, positive provides and negative
     * releases. A branch with a balance but no listed row (an inactive
     * branch whose loans are all paid) is added with zeros, so it can be
     * released. The no-branch balance is shown and never adjusted.
     *
     * @param  array{as_of: string, branches: list<array<string, mixed>>, totals: array<string, int>}  $base  self::forDate()
     * @param  array{branches: array<int, int>, unassigned: int}  $balances  self::balances()
     * @return array{as_of: string, rates: list<array<string, string>>, branches: list<array<string, mixed>>, totals: array{loan_count: int, principal: int, required: int, current_balance: int, adjustment: int}, unassigned_balance: int}
     */
    public function withBalances(array $base, array $balances): array
    {
        $rows = $base['branches'];
        $listed = array_column(array_column($rows, 'branch'), 'id');
        $missing = array_diff(array_keys(array_filter($balances['branches'])), $listed);

        if ($missing !== []) {
            foreach (Branch::query()->whereIn('id', $missing)->get(['id', 'name']) as $branch) {
                $rows[] = $this->branchRow($branch, []);
            }

            usort($rows, fn (array $a, array $b): int => $a['branch']['id'] <=> $b['branch']['id']);
        }

        $rows = array_map(function (array $row) use ($balances): array {
            $current = $balances['branches'][$row['branch']['id']] ?? 0;

            return [...$row, 'current_balance' => $current, 'adjustment' => $row['required'] - $current];
        }, $rows);

        return [
            'as_of' => $base['as_of'],
            'rates' => $this->rates(),
            'branches' => $rows,
            'totals' => [
                'loan_count' => $base['totals']['loan_count'],
                'principal' => array_sum(array_column($rows, 'principal')),
                'required' => array_sum(array_column($rows, 'required')),
                'current_balance' => array_sum(array_column($rows, 'current_balance')),
                'adjustment' => array_sum(array_column($rows, 'adjustment')),
            ],
            'unassigned_balance' => $balances['unassigned'],
        ];
    }

    /**
     * The Provisioning report's `principal_basis`: self::forDate()'s figures
     * in pesos, each converted once from its centavo total. `91_120` and
     * `over_120` fold into `over_90`. A bucket's `required_allowance` is the
     * sum of each branch's bucket figure, so the totals equal what a run
     * would post.
     *
     * @param  list<int>|null  $branchIds
     * @return array{buckets: array<string, array{principal: float, count: int, rate_percent: string, required_allowance: float}>, totals: array{principal: float, count: int, required_allowance: float}, basis_note: string}
     */
    public function principalBasis(string $asOf, ?array $branchIds): array
    {
        $sums = array_fill_keys(array_keys(ReportService::PROVISION_RATES), ['principal' => 0, 'count' => 0, 'required' => 0]);

        foreach ($this->forDate($asOf, $branchIds)['branches'] as $branch) {
            foreach ($branch['buckets'] as $bucket) {
                $key = self::RATE_KEYS[$bucket['bucket']];
                $sums[$key]['principal'] += $bucket['principal'];
                $sums[$key]['count'] += $bucket['loan_count'];
                $sums[$key]['required'] += $bucket['required'];
            }
        }

        $buckets = [];

        foreach ($sums as $key => $sum) {
            $buckets[$key] = [
                'principal' => Money::fromCentavos($sum['principal']),
                'count' => $sum['count'],
                'rate_percent' => self::percent($key),
                'required_allowance' => Money::fromCentavos($sum['required']),
            ];
        }

        return [
            'buckets' => $buckets,
            'totals' => [
                'principal' => Money::fromCentavos(array_sum(array_column($sums, 'principal'))),
                'count' => array_sum(array_column($sums, 'count')),
                'required_allowance' => Money::fromCentavos(array_sum(array_column($sums, 'required'))),
            ],
            'basis_note' => self::BASIS_NOTE,
        ];
    }

    /** `$principal` centavos at a bucket's rate: {@see self::requiredAt()}. */
    public static function requiredFor(int $principal, string $bucket): int
    {
        return self::requiredAt($principal, self::decimal(self::RATE_KEYS[$bucket]));
    }

    /**
     * `$principal` centavos at `$rate` (a decimal string, "0.05"), exactly,
     * then rounded half up to the centavo, in integers only.
     *
     * With the rate as the reduced fraction `num / den` and the principal
     * split as `p = q × den + r` (0 ≤ r < den), p × rate = q × num + r × num
     * / den, so the result is `q × num + intdiv(2 × r × num + den, 2 × den)`:
     * the whole part needs no rounding, and the remainder's share is rounded
     * half up once. The remainder's product is worked out by halving and
     * doubling ({@see self::mulDiv()}), so it never overflows, however many
     * decimals the rate has. The principal is never negative (LoanListTotals
     * floors each period at zero).
     *
     * @throws \LogicException only when the result itself is too large for an integer
     */
    public static function requiredAt(int $principal, string $rate): int
    {
        if ($principal <= 0) {
            return 0;
        }

        [$numerator, $denominator] = self::fraction($rate);
        $whole = intdiv($principal, $denominator);
        $remainder = $principal % $denominator;

        if ($numerator > 0 && $whole > intdiv(PHP_INT_MAX, $numerator)) {
            throw new \LogicException("Allowance: {$principal} centavos at a rate of {$rate} is too large to work out exactly.");
        }

        // r × num ÷ den, rounded half up: the quotient, plus one when twice
        // the remainder reaches den (2 × left < 2 × den, so it never overflows).
        [$share, $left] = self::mulDiv($remainder, $numerator, $denominator);

        return $whole * $numerator + $share + ($left * 2 >= $denominator ? 1 : 0);
    }

    /**
     * `a × b` divided by `c`, as quotient and remainder, for 0 ≤ a < c and
     * b ≥ 0, without ever forming `a × b`: b is read bit by bit while `a`
     * doubles modulo c (the long multiplication in binary), so no step goes
     * above 2 × c. The quotient is below b.
     *
     * @return array{0: int, 1: int} quotient, remainder
     */
    private static function mulDiv(int $a, int $b, int $c): array
    {
        $quotient = 0;
        $remainder = 0;
        // a × 2^k, kept as (aQuotient × c + aRemainder) while k grows.
        $aQuotient = 0;
        $aRemainder = $a;

        while ($b > 0) {
            if (($b & 1) === 1) {
                $quotient += $aQuotient;
                $remainder += $aRemainder;

                if ($remainder >= $c) {
                    $remainder -= $c;
                    $quotient++;
                }
            }

            $b >>= 1;

            if ($b > 0) {
                $aQuotient *= 2;
                $aRemainder *= 2;

                if ($aRemainder >= $c) {
                    $aRemainder -= $c;
                    $aQuotient++;
                }
            }
        }

        return [$quotient, $remainder];
    }

    /**
     * @param  array<string, array{loan_count: int, principal: int}>  $buckets  this branch's figures by bucket
     * @return array{branch: array{id: int, name: string}, buckets: list<array{bucket: string, loan_count: int, principal: int, rate_percent: string, required: int}>, principal: int, required: int}
     */
    private function branchRow(Branch $branch, array $buckets): array
    {
        $rows = array_map(function (string $bucket) use ($buckets): array {
            $principal = $buckets[$bucket]['principal'] ?? 0;

            return [
                'bucket' => $bucket,
                'loan_count' => $buckets[$bucket]['loan_count'] ?? 0,
                'principal' => $principal,
                'rate_percent' => self::percent(self::RATE_KEYS[$bucket]),
                'required' => self::requiredFor($principal, $bucket),
            ];
        }, self::BUCKETS);

        return [
            'branch' => ['id' => (int) $branch->id, 'name' => (string) $branch->name],
            'buckets' => $rows,
            'principal' => array_sum(array_column($rows, 'principal')),
            'required' => array_sum(array_column($rows, 'required')),
        ];
    }

    /**
     * A PROVISION_RATES rate as a decimal string: 0.05 is "0.05". Fifteen
     * decimals are as many as a double holds exactly, so the float's binary
     * tail (0.05 is stored as 0.05000000000000000277…) is dropped and the
     * rate the policy states is the one used.
     */
    private static function decimal(string $key): string
    {
        $text = rtrim(sprintf('%.15F', ReportService::PROVISION_RATES[$key]), '0');

        return str_ends_with($text, '.') ? $text.'0' : $text;
    }

    /**
     * A decimal rate as an exact fraction of integers, reduced: "0.05" is
     * 1 / 20, "0.5" is 1 / 2 and "1" is 1 / 1.
     *
     * @return array{0: int, 1: int} numerator, denominator
     */
    private static function fraction(string $rate): array
    {
        [$whole, $decimals] = array_pad(explode('.', $rate, 2), 2, '');
        $decimals = rtrim($decimals, '0');
        $numerator = (int) ($whole.$decimals);
        $denominator = 10 ** strlen($decimals);
        $divisor = self::gcd($numerator, $denominator);

        return [intdiv($numerator, $divisor), intdiv($denominator, $divisor)];
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return max($a, 1);
    }

    /**
     * A rate as a percentage string, never rounded: the decimal point moved
     * two places right, so 0.05 is "5", 0.5 is "50" and 0.125 is "12.5".
     */
    private static function percent(string $key): string
    {
        [$whole, $decimals] = explode('.', self::decimal($key));
        $decimals = str_pad($decimals, 2, '0');
        $whole = ltrim($whole.substr($decimals, 0, 2), '0');
        $fraction = rtrim(substr($decimals, 2), '0');

        return ($whole === '' ? '0' : $whole).($fraction === '' ? '' : '.'.$fraction);
    }

    /**
     * Every date, so the caller sees journals dated after `as_of` too.
     *
     * @return list<array{0: int|null, 1: int, 2: string}> [branch id, balance, date] per branch and date
     */
    private function plainBalances(int $accountId): array
    {
        return DB::table('accounting_journal_lines as l')
            ->join('accounting_journals as j', 'j.id', '=', 'l.accounting_journal_id')
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            ->where('l.accounting_account_id', $accountId)
            ->groupBy('j.branch_id', 'j.date')
            ->selectRaw('j.branch_id, j.date, COALESCE(SUM(l.credit), 0) - COALESCE(SUM(l.debit), 0) AS balance')
            ->get()
            ->map(fn (object $row): array => [
                $row->branch_id === null ? null : (int) $row->branch_id,
                (int) $row->balance,
                substr((string) $row->date, 0, 10),
            ])
            ->all();
    }

    /**
     * Every date, so the caller sees journals dated after `as_of` too.
     *
     * @return list<array{0: int|null, 1: int, 2: string}> [branch id, balance, date] per journal
     */
    private function lockedBalances(int $accountId): array
    {
        $byJournal = DB::table('accounting_journal_lines')
            ->where('accounting_account_id', $accountId)
            ->groupBy('accounting_journal_id')
            ->selectRaw('accounting_journal_id, COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS balance')
            ->lockForUpdate()
            ->pluck('balance', 'accounting_journal_id');

        if ($byJournal->isEmpty()) {
            return [];
        }

        return DB::table('accounting_journals')
            ->whereIn('id', $byJournal->keys()->all())
            ->whereIn('status', AccountingJournal::HISTORICAL_STATUSES)
            ->lockForUpdate()
            ->get(['id', 'branch_id', 'date'])
            ->map(fn (object $journal): array => [
                $journal->branch_id === null ? null : (int) $journal->branch_id,
                (int) $byJournal[(int) $journal->id],
                substr((string) $journal->date, 0, 10),
            ])
            ->values()
            ->all();
    }
}
