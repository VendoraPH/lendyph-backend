<?php

namespace App\Services\Savings\Reports;

use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\Branch;
use App\Models\User;
use App\Notifications\StaffNotification;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\Money;
use App\Services\Accounting\PostingRules;
use App\Services\BranchAccess;
use App\Services\Savings\SavingsMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The savings subledger against the general ledger (design §6), per branch,
 * as of the end of a day, worked out when read (there is no control table):
 *
 * - Savings Deposits (role `savings_deposits`, 2050): every savings row's
 *   credits less debits dated on or before the day, by the account's branch,
 *   against that account's posted and reversed journal lines dated on or
 *   before the day, credits less debits, by the journal's branch;
 * - Time Deposits (role `time_deposits`, 2060): the principal of every time
 *   deposit placed by the day and not settled by it, against that account
 *   the same way.
 *
 * Every active branch the caller can see is listed with ₱0.00 where nothing
 * moved. Journals with no branch (opening balances) show on their own row,
 * to `branches:view_all` only. The difference is subledger less GL; anything
 * but ₱0.00 is for the accountant (an unimported legacy balance, decision 7,
 * shows here). The nightly command tells the `savings:post` holders who can
 * see a differing branch, once per day.
 */
class GlControl
{
    public const NOTIFICATION_TYPE = 'savings_gl_difference';

    private const ACCOUNTS = [
        PostingRules::SAVINGS_DEPOSITS_ROLE => ['code' => '2050', 'name' => 'Savings Deposits'],
        PostingRules::TIME_DEPOSITS_ROLE => ['code' => '2060', 'name' => 'Time Deposits'],
    ];

    public function __construct(private readonly BranchAccess $branches) {}

    /**
     * @param  list<int>|null  $branchIds  the caller's branches; null for every branch
     * @param  bool  $fromAccountBalances  read today's savings subledger from the accounts' own
     *                                     balances instead of every row (gap round S5, the dashboards);
     *                                     used only for today, when the two are the same
     * @return array{as_of: string, rows: list<array<string, mixed>>, totals: array{subledger_balance: string, gl_balance: string, difference: string}, is_balanced: bool, differences: int}
     */
    public function build(string $asOf, ?array $branchIds, ?int $branchId = null, bool $fromAccountBalances = false): array
    {
        $scope = $branchId !== null ? [$branchId] : $branchIds;
        $subledger = $fromAccountBalances && $asOf === now()->toDateString() ? $this->accountBalances() : $this->savingsSubledger($asOf);
        $figures = [
            PostingRules::SAVINGS_DEPOSITS_ROLE => [$subledger, $this->generalLedger(PostingRules::SAVINGS_DEPOSITS_ROLE, $asOf)],
            PostingRules::TIME_DEPOSITS_ROLE => [$this->timeDepositSubledger($asOf), $this->generalLedger(PostingRules::TIME_DEPOSITS_ROLE, $asOf)],
        ];
        $accounts = $this->accounts();

        $listed = Branch::query()
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $this->movedBranchIds($figures)))
            ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope))
            ->orderBy('name')
            ->get(['id', 'name']);

        $rows = [];
        $totals = ['subledger' => 0, 'gl' => 0];

        $add = function (?int $id, ?string $name) use (&$rows, &$totals, $figures, $accounts): void {
            foreach ($figures as $role => [$subledger, $ledger]) {
                $key = $id ?? 0;
                $sub = $subledger[$key] ?? 0;
                $gl = $ledger[$key] ?? 0;

                if ($id === null && $sub === 0 && $gl === 0) {
                    continue;
                }

                if ($accounts[$role]['id'] === null && $sub === 0 && $gl === 0) {
                    continue;
                }

                $totals['subledger'] += $sub;
                $totals['gl'] += $gl;
                $rows[] = [
                    'branch_id' => $id,
                    'branch_name' => $name ?? 'No branch',
                    'role' => $role,
                    'account_id' => $accounts[$role]['id'],
                    'account_code' => $accounts[$role]['code'],
                    'account_name' => $accounts[$role]['name'],
                    'subledger_balance' => SavingsMath::pesos($sub),
                    'gl_balance' => SavingsMath::pesos($gl),
                    'difference' => SavingsMath::pesos($sub - $gl),
                    'difference_centavos' => $sub - $gl,
                ];
            }
        };

        foreach ($listed as $branch) {
            $add((int) $branch->id, $branch->name);
        }

        if ($scope === null) {
            $add(null, null);
        }

        $differences = count(array_filter($rows, fn (array $row) => $row['difference_centavos'] !== 0));

        return [
            'as_of' => $asOf,
            'rows' => array_map(fn (array $row) => collect($row)->except('difference_centavos')->all(), $rows),
            'totals' => [
                'subledger_balance' => SavingsMath::pesos($totals['subledger']),
                'gl_balance' => SavingsMath::pesos($totals['gl']),
                'difference' => SavingsMath::pesos($totals['subledger'] - $totals['gl']),
            ],
            'is_balanced' => $differences === 0,
            'differences' => $differences,
        ];
    }

    /** Whether there is any savings record at all. */
    public function hasSavings(): bool
    {
        return DB::table('savings_accounts')->exists() || DB::table('time_deposits')->exists();
    }

    /**
     * Tell each active `savings:post` holder about the differing rows in the
     * branches they can see, at most once per day each.
     *
     * @return int how many users were told
     */
    public function notify(array $control): int
    {
        $differing = collect($control['rows'])->reject(fn (array $row) => $row['difference'] === '0.00');

        if ($differing->isEmpty()) {
            return 0;
        }

        $asOf = $control['as_of'];
        $already = DB::table('notifications')
            ->where('type', self::NOTIFICATION_TYPE)
            ->where('notifiable_type', User::class)
            ->where('data->data->as_of', $asOf)
            ->pluck('notifiable_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $told = 0;

        foreach ($this->recipients() as $user) {
            if (in_array((int) $user->id, $already, true)) {
                continue;
            }

            $seen = $differing->filter(fn (array $row) => $this->branches->canSee($user, $row['branch_id']));

            if ($seen->isEmpty()) {
                continue;
            }

            Notification::send($user, $this->message($asOf, $seen));
            $told++;
        }

        return $told;
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function message(string $asOf, Collection $rows): StaffNotification
    {
        $day = CarbonImmutable::parse($asOf)->format('M j, Y');
        $parts = $rows->map(fn (array $row) => sprintf(
            '%s, %s %s: %s',
            $row['branch_name'],
            $row['account_code'] ?? '',
            $row['account_name'],
            Money::format(SavingsMath::signedCentavos($row['difference'])),
        ))->values()->all();

        return new StaffNotification(
            self::NOTIFICATION_TYPE,
            'Savings and the general ledger differ',
            "As of {$day}, the savings subledger and the general ledger differ: ".implode('; ', $parts).'. Open the Savings GL Control report.',
            '/reports/savings_gl_control',
            ['as_of' => $asOf],
        );
    }

    /** @return Collection<int, User> */
    private function recipients(): Collection
    {
        return User::query()->active()->with(['roles', 'permissions'])->orderBy('id')->get()
            ->filter(fn (User $user) => $user->can('savings:post'))
            ->values();
    }

    /**
     * Savings rows' credits less debits by the account's branch (0 for none).
     *
     * @return array<int, int>
     */
    private function savingsSubledger(string $asOf): array
    {
        return DB::table('savings_transactions AS t')
            ->join('savings_accounts AS a', 'a.id', '=', 't.account_id')
            ->where('t.date', '<=', $asOf)
            ->groupBy('a.branch_id')
            ->selectRaw('a.branch_id AS branch_id, COALESCE(SUM(t.credit), 0) AS credits, COALESCE(SUM(t.debit), 0) AS debits')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) ($row->branch_id ?? 0) => SavingsMath::centavos($row->credits) - SavingsMath::centavos($row->debits)])
            ->all();
    }

    /**
     * Today's savings subledger from the accounts' own balances, by branch
     * (SavingsLedger keeps `ledger_balance` equal to the account's rows, and
     * no row is dated after today).
     *
     * @return array<int, int>
     */
    private function accountBalances(): array
    {
        return DB::table('savings_accounts')
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COALESCE(SUM(ledger_balance), 0) AS balance')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) ($row->branch_id ?? 0) => SavingsMath::signedCentavos($row->balance)])
            ->all();
    }

    /**
     * The principal of time deposits outstanding at the end of the day.
     *
     * @return array<int, int>
     */
    private function timeDepositSubledger(string $asOf): array
    {
        return DB::table('time_deposits')
            ->where('placement_date', '<=', $asOf)
            ->where(fn ($q) => $q->whereNull('settled_on')->orWhere('settled_on', '>', $asOf))
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COALESCE(SUM(principal), 0) AS principal')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) ($row->branch_id ?? 0) => SavingsMath::centavos($row->principal)])
            ->all();
    }

    /**
     * The role's account, credits less debits, by journal branch (0 for none).
     *
     * @return array<int, int>
     */
    private function generalLedger(string $role, string $asOf): array
    {
        $accountId = $this->accounts()[$role]['id'];

        if ($accountId === null) {
            return [];
        }

        return DB::table('accounting_journal_lines AS l')
            ->join('accounting_journals AS j', 'j.id', '=', 'l.accounting_journal_id')
            ->whereIn('j.status', AccountingJournal::HISTORICAL_STATUSES)
            ->where('j.date', '<=', $asOf)
            ->where('l.accounting_account_id', $accountId)
            ->groupBy('j.branch_id')
            ->selectRaw('j.branch_id AS branch_id, COALESCE(SUM(l.credit), 0) AS credits, COALESCE(SUM(l.debit), 0) AS debits')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) ($row->branch_id ?? 0) => (int) $row->credits - (int) $row->debits])
            ->all();
    }

    /** @return array<string, array{id: int|null, code: string, name: string}> */
    private function accounts(): array
    {
        $map = AccountMap::chartExists() ? AccountMap::resolve() : AccountMap::fromArray([]);
        $accounts = [];

        foreach (self::ACCOUNTS as $role => $fallback) {
            $account = $map->has($role) ? AccountingAccount::query()->find($map->accountFor($role)) : null;
            $accounts[$role] = [
                'id' => $account?->id,
                'code' => $account->code ?? $fallback['code'],
                'name' => $account->name ?? $fallback['name'],
            ];
        }

        return $accounts;
    }

    /**
     * @param  array<string, array{0: array<int, int>, 1: array<int, int>}>  $figures
     * @return list<int>
     */
    private function movedBranchIds(array $figures): array
    {
        $ids = [];

        foreach ($figures as [$subledger, $ledger]) {
            $ids = [...$ids, ...array_keys($subledger), ...array_keys($ledger)];
        }

        return array_values(array_filter(array_unique($ids)));
    }
}
