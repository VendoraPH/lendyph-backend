<?php

namespace App\Services\Savings\Interest;

use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Models\SavingsInterestRunLine;
use App\Models\SavingsProduct;
use App\Models\SavingsTaxRule;
use App\Models\User;
use App\Services\LoanWriteTransaction;
use App\Services\Savings\DailyBalances;
use App\Services\Savings\InterestCalculator;
use App\Services\Savings\RateBook;
use App\Services\Savings\SavingsMath;
use App\Services\Savings\TaxBook;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Works out an interest run's figures and saves drafts (design §5).
 *
 * One run per product, branch and crediting period. A change of frequency
 * leaves one transitional period, from the day after the last posted run to
 * the end of the new-frequency period that holds it (review M2). Each account in the
 * branch that was open during the period gets a line: from the later of the
 * period's start, its opening day and the day after its interest was last
 * credited, to the period's end, through InterestCalculator. A line that
 * earns nothing says why (`skip_reason`): `closed`, `no_days`, `no_rate`,
 * `no_balance`, `below_minimum_adb` or `below_earning_tier` (every day's
 * balance stayed in a 0% tier of a ladder whose higher tiers pay).
 *
 * The same figures() serves the preview, the draft and the re-check at
 * posting (RunPoster), so the three can only agree or answer 409.
 */
class RunBuilder
{
    public const SKIP_CLOSED = 'closed';

    public const SKIP_NO_RATE = 'no_rate';

    /** Every day stayed in a 0% tier, below the first tier that earns interest. */
    public const SKIP_BELOW_EARNING_TIER = 'below_earning_tier';

    public const SKIP_NO_BALANCE = 'no_balance';

    /** The fields a draft line must still hold at posting. */
    public const COMPARED = ['days', 'balance_days', 'average_daily_balance', 'rate', 'gross', 'tax_rate', 'tax', 'net', 'skip_reason'];

    public function __construct(
        private readonly DailyBalances $balances,
        private readonly RateBook $rates,
        private readonly TaxBook $taxes,
        private readonly InterestCalculator $calculator,
    ) {}

    /**
     * The accounts a run of this product, branch and period covers: opened by
     * its end and not closed before its start, in id order.
     *
     * @return Collection<int, SavingsAccount>
     */
    public function accounts(int $productId, int $branchId, string $start, string $end): Collection
    {
        return SavingsAccount::query()
            ->with('borrower')
            ->where('product_id', $productId)
            ->where('branch_id', $branchId)
            ->where('opened_on', '<=', $end)
            ->where(fn ($q) => $q->where('status', '!=', SavingsAccount::STATUS_CLOSED)
                ->orWhereNull('closed_on')
                ->orWhere('closed_on', '>=', $start))
            ->orderBy('id')
            ->get();
    }

    /**
     * Each account's figures, in centavos, keyed by account id.
     *
     * @param  Collection<int, SavingsAccount>|null  $accounts  defaults to accounts()
     * @return array<int, array{account_id: int, days: int, balance_days: int, average_daily_balance: int, rate: string, rate_segments: list<array<string, mixed>>, gross: int, tax_rate: string, tax: int, net: int, skip_reason: string|null}>
     */
    public function figures(SavingsProduct $product, int $branchId, string $start, string $end, ?Collection $accounts = null): array
    {
        $accounts ??= $this->accounts($product->id, $branchId, $start, $end);
        $taxRate = $this->taxes->rateOn(SavingsTaxRule::HOLDER_MEMBER, $end);
        $minimumAdb = SavingsMath::centavos($product->minimum_adb_for_interest);
        $lines = [];

        $open = $accounts->reject(fn (SavingsAccount $account) => $account->isClosed());

        foreach ($accounts->filter(fn (SavingsAccount $account) => $account->isClosed()) as $account) {
            $lines[$account->id] = $this->line($account->id, [
                'days' => 0, 'balance_days' => 0, 'average_daily_balance' => 0, 'rate' => '0.0000', 'rate_segments' => [],
                'gross' => 0, 'tax_rate' => $taxRate, 'tax' => 0, 'net' => 0, 'skip_reason' => self::SKIP_CLOSED,
            ]);
        }

        foreach ($open->groupBy(fn (SavingsAccount $account) => $this->firstDay($account, $start)) as $from => $group) {
            $loaded = $from <= $end ? $this->balances->load($group->pluck('id'), $from, $end) : [];
            $segments = $from <= $end ? $this->rates->segments($product->id, null, $from, $end) : [];
            $noRate = collect($segments)->every(fn (array $segment) => SavingsMath::rateUnits($segment['rate']) === 0
                && collect($segment['tiers'] ?? [])->every(fn (array $tier) => SavingsMath::rateUnits($tier['rate']) === 0));

            foreach ($group as $account) {
                $figures = $this->calculator->calculate(
                    $loaded[$account->id]['opening'] ?? 0,
                    $loaded[$account->id]['deltas'] ?? [],
                    $from,
                    $end,
                    $segments,
                    $minimumAdb,
                    $taxRate,
                );

                if ($figures['skip_reason'] !== InterestCalculator::SKIP_NO_DAYS) {
                    $reason = match (true) {
                        $noRate => self::SKIP_NO_RATE,
                        $figures['balance_days'] <= 0 => self::SKIP_NO_BALANCE,
                        $figures['skip_reason'] === null && self::earnedOnlyZeroTiers($figures['rate_segments']) => self::SKIP_BELOW_EARNING_TIER,
                        default => null,
                    };

                    if ($reason !== null) {
                        $figures = [...$figures, 'gross' => 0, 'tax' => 0, 'net' => 0, 'skip_reason' => $reason];
                    }
                }

                $lines[$account->id] = $this->line($account->id, $figures);
            }
        }

        ksort($lines);

        return $lines;
    }

    /**
     * The branches a run covers: those holding an account on the product in
     * the period, narrowed to the caller's branches and to `$requested`.
     *
     * @param  list<int>|null  $visible  null for every branch
     * @param  list<int>|null  $requested
     * @return list<int>
     */
    public function branches(int $productId, string $start, string $end, ?array $visible, ?array $requested): array
    {
        return SavingsAccount::query()
            ->where('product_id', $productId)
            ->whereNotNull('branch_id')
            ->where('opened_on', '<=', $end)
            ->where(fn ($q) => $q->where('status', '!=', SavingsAccount::STATUS_CLOSED)->orWhereNull('closed_on')->orWhere('closed_on', '>=', $start))
            ->when($visible !== null, fn ($q) => $q->whereIn('branch_id', $visible))
            ->when($requested !== null, fn ($q) => $q->whereIn('branch_id', $requested))
            ->distinct()
            ->orderBy('branch_id')
            ->pluck('branch_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The period's end, once the period is one this product and these
     * branches can run now; otherwise 422 on `period_start` (or `product_id`).
     *
     * @param  list<int>  $branchIds
     *
     * @throws ValidationException
     */
    public function assertRunnable(SavingsProduct $product, string $start, array $branchIds): string
    {
        if ($product->isTimeDeposit()) {
            $this->refuse('product_id', "{$product->name} is a time deposit product. Time deposits earn on their own terms; interest runs are for regular savings.");
        }

        if (! InterestPeriod::isStart($product->interest_crediting, $start)) {
            $blocker = $this->transitionalBlocker($product, $start, $branchIds);

            if ($blocker !== null) {
                $this->refuse('period_start', "Interest on {$product->name} is ".InterestPeriod::startsInWords($product->interest_crediting).'.'.$blocker);
            }
        }

        $end = $this->periodEnd($product, $start);
        $today = now()->toDateString();

        if ($end >= $today) {
            $this->refuse('period_start', 'The period runs to '.$this->day($end).' and has not ended yet. It can be run from '.$this->day(CarbonImmutable::parse($end)->addDay()->toDateString()).'.');
        }

        $names = Branch::query()->whereIn('id', $branchIds)->pluck('name', 'id');

        foreach ($branchIds as $branchId) {
            $branch = $names[$branchId] ?? 'this branch';

            $earlierLive = SavingsInterestRun::query()
                ->where('product_id', $product->id)
                ->where('branch_id', $branchId)
                ->whereIn('status', ['draft', 'approved'])
                ->where('period_start', '<', $start)
                ->orderBy('period_start')
                ->first();

            if ($earlierLive !== null) {
                $this->refuse('period_start', "The run for {$branch} from ".$this->day($earlierLive->period_start->toDateString())
                    .' to '.$this->day($earlierLive->period_end->toDateString()).' is not posted yet. Post or reject it before running a later period.');
            }

            $expected = $this->nextStart($product, $branchId);

            if ($expected !== null && $expected !== $start) {
                $this->refuse('period_start', "The next interest period for {$branch} starts on ".$this->day($expected).', not '.$this->day($start).'.');
            }
        }

        return $end;
    }

    /**
     * The last day of the period starting on `$start`: a whole crediting
     * period, or, for a transitional period after the product's frequency
     * changed, the end of the current-frequency period that holds `$start`.
     */
    public function periodEnd(SavingsProduct $product, string $start): string
    {
        $crediting = $product->interest_crediting;

        return InterestPeriod::endOf($crediting, InterestPeriod::isStart($crediting, $start) ? $start : InterestPeriod::startOf($crediting, $start));
    }

    /**
     * Why a start that isn't a period start under the product's current
     * frequency can't be the transitional period after a frequency change
     * (review M2), naming the branch that blocks it; null when every branch
     * has a posted run and continues from it on `$start`.
     *
     * @param  list<int>  $branchIds
     */
    private function transitionalBlocker(SavingsProduct $product, string $start, array $branchIds): ?string
    {
        if ($branchIds === []) {
            return '';
        }

        $names = Branch::query()->whereIn('id', $branchIds)->pluck('name', 'id');

        foreach ($branchIds as $branchId) {
            $branch = $names[$branchId] ?? 'A branch';
            $posted = SavingsInterestRun::query()->where('product_id', $product->id)->where('branch_id', $branchId)->where('status', 'posted')->exists();

            if (! $posted) {
                return " {$branch} has no posted run on this product, so its first period can't start on ".$this->day($start).'.';
            }

            $next = $this->nextStart($product, $branchId);

            if ($next !== $start) {
                return " {$branch}'s next interest period starts on ".$this->day((string) $next).', not '.$this->day($start).'.';
            }
        }

        return null;
    }

    /**
     * The day the next run for a branch must start: the day after the latest
     * posted run, or, before any, the start of the period the branch's first
     * account opened in. Null when the branch has no account on the product.
     */
    public function nextStart(SavingsProduct $product, int $branchId): ?string
    {
        $posted = SavingsInterestRun::query()
            ->where('product_id', $product->id)
            ->where('branch_id', $branchId)
            ->where('status', 'posted')
            ->max('period_end');

        if ($posted !== null) {
            return CarbonImmutable::parse($posted)->addDay()->toDateString();
        }

        $firstOpened = SavingsAccount::query()->where('product_id', $product->id)->where('branch_id', $branchId)->min('opened_on');

        return $firstOpened === null ? null : InterestPeriod::startOf($product->interest_crediting, CarbonImmutable::parse($firstOpened)->toDateString());
    }

    /**
     * What a run would credit, per branch, writing nothing.
     *
     * @param  list<int>|null  $visible
     * @param  list<int>|null  $requested
     * @return array{period_start: string, period_end: string, runs: list<array{branch_id: int, accounts: Collection<int, SavingsAccount>, lines: array<int, array<string, mixed>>}>}
     *
     * @throws ValidationException
     */
    public function preview(SavingsProduct $product, string $start, ?array $visible, ?array $requested): array
    {
        $branchIds = $product->isTimeDeposit() ? [] : $this->branches($product->id, $start, $this->periodEnd($product, $start), $visible, $requested);
        $end = $this->assertRunnable($product, $start, $branchIds);
        $runs = [];

        foreach ($branchIds as $branchId) {
            $accounts = $this->accounts($product->id, $branchId, $start, $end);
            $runs[] = ['branch_id' => $branchId, 'accounts' => $accounts->keyBy('id'), 'lines' => $this->figures($product, $branchId, $start, $end, $accounts)];
        }

        return ['period_start' => $start, 'period_end' => $end, 'runs' => $runs];
    }

    /**
     * One draft per branch, with its lines, in one transaction. 409 while a
     * live (draft or approved) run holds the period in any of the branches.
     *
     * @param  list<int>|null  $visible
     * @param  list<int>|null  $requested
     * @return list<SavingsInterestRun>
     *
     * @throws ValidationException
     */
    public function create(SavingsProduct $product, string $start, ?array $visible, ?array $requested, User $maker): array
    {
        $conflict = 'Another interest run for this period was saved at the same time. Reload and try again.';

        return LoanWriteTransaction::run(function () use ($product, $start, $visible, $requested, $maker): array {
            // The product row first (review M1): a create and a reversal of the
            // same product queue here, so a run is never created against a
            // previous period that is being reversed at the same moment.
            $product = SavingsProduct::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $preview = $this->preview($product, $start, $visible, $requested);

            if ($preview['runs'] === []) {
                $this->refuse('product_id', "There are no {$product->name} accounts to credit in the branches chosen for this period.");
            }

            $created = [];

            foreach ($preview['runs'] as $run) {
                $liveKey = SavingsInterestRun::liveKey($product->id, $run['branch_id'], $preview['period_start']);

                if (SavingsInterestRun::query()->where('live_key', $liveKey)->lockForUpdate()->exists()) {
                    $branch = Branch::query()->whereKey($run['branch_id'])->value('name') ?? 'this branch';

                    throw LoanWriteTransaction::conflict("An interest run for {$product->name} at {$branch} from ".$this->day($preview['period_start'])
                        .' is already open. Approve, reject or post it first.');
                }

                $totals = self::totals($run['lines']);
                $model = SavingsInterestRun::query()->create([
                    'product_id' => $product->id,
                    'branch_id' => $run['branch_id'],
                    'period_start' => $preview['period_start'],
                    'period_end' => $preview['period_end'],
                    'status' => 'draft',
                    'live_key' => $liveKey,
                    'account_count' => $totals['account_count'],
                    'total_gross' => SavingsMath::pesos($totals['gross']),
                    'total_tax' => SavingsMath::pesos($totals['tax']),
                    'total_net' => SavingsMath::pesos($totals['net']),
                    'created_by' => $maker->id,
                ]);

                foreach ($run['lines'] as $line) {
                    SavingsInterestRunLine::query()->create([
                        'run_id' => $model->id,
                        'account_id' => $line['account_id'],
                        'days' => $line['days'],
                        'balance_days' => $line['balance_days'],
                        'average_daily_balance' => SavingsMath::pesos($line['average_daily_balance']),
                        'rate' => $line['rate'],
                        'rate_segments' => $line['rate_segments'],
                        'gross_interest' => SavingsMath::pesos($line['gross']),
                        'tax_rate' => $line['tax_rate'],
                        'withholding_tax' => SavingsMath::pesos($line['tax']),
                        'net_interest' => SavingsMath::pesos($line['net']),
                        'skip_reason' => $line['skip_reason'],
                    ]);
                }

                $created[] = $model;
            }

            return $created;
        }, 'savings_interest_runs_live_key_unique', $conflict);
    }

    /**
     * Whether a line with tiers earned 0% on every day: each tiered
     * segment's days all fell in 0% tiers, and every other segment is 0%.
     *
     * @param  list<array<string, mixed>>  $segments  the line's `rate_segments`
     */
    private static function earnedOnlyZeroTiers(array $segments): bool
    {
        $tiered = false;

        foreach ($segments as $segment) {
            if (isset($segment['by_tier'])) {
                $tiered = true;

                foreach ($segment['by_tier'] as $tier) {
                    if (SavingsMath::rateUnits($tier['rate']) > 0) {
                        return false;
                    }
                }
            } elseif (SavingsMath::rateUnits($segment['rate']) > 0) {
                return false;
            }
        }

        return $tiered;
    }

    /**
     * A saved line in the shape figures() returns, for comparing.
     *
     * @return array<string, mixed>
     */
    public static function stored(SavingsInterestRunLine $line): array
    {
        return [
            'account_id' => $line->account_id,
            'days' => $line->days,
            'balance_days' => (int) $line->balance_days,
            'average_daily_balance' => SavingsMath::centavos($line->average_daily_balance),
            'rate' => SavingsMath::rateString(SavingsMath::rateUnits((string) $line->rate)),
            'gross' => SavingsMath::centavos($line->gross_interest),
            'tax_rate' => SavingsMath::rateString(SavingsMath::rateUnits((string) $line->tax_rate)),
            'tax' => SavingsMath::centavos($line->withholding_tax),
            'net' => SavingsMath::centavos($line->net_interest),
            'skip_reason' => $line->skip_reason,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{account_count: int, gross: int, tax: int, net: int}
     */
    public static function totals(array $lines): array
    {
        $totals = ['account_count' => count($lines), 'gross' => 0, 'tax' => 0, 'net' => 0];

        foreach ($lines as $line) {
            $totals['gross'] += $line['gross'];
            $totals['tax'] += $line['tax'];
            $totals['net'] += $line['net'];
        }

        return $totals;
    }

    /** The first day of the period this account earns on. */
    private function firstDay(SavingsAccount $account, string $start): string
    {
        $first = CarbonImmutable::parse($start)->max(CarbonImmutable::parse($account->opened_on->toDateString()));

        if ($account->interest_credited_through !== null) {
            $first = $first->max(CarbonImmutable::parse($account->interest_credited_through->toDateString())->addDay());
        }

        return $first->toDateString();
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return array<string, mixed>
     */
    private function line(int $accountId, array $figures): array
    {
        return [
            'account_id' => $accountId,
            'days' => (int) $figures['days'],
            'balance_days' => (int) $figures['balance_days'],
            'average_daily_balance' => (int) $figures['average_daily_balance'],
            'rate' => (string) $figures['rate'],
            'rate_segments' => $figures['rate_segments'],
            'gross' => (int) $figures['gross'],
            'tax_rate' => (string) $figures['tax_rate'],
            'tax' => (int) $figures['tax'],
            'net' => (int) $figures['net'],
            'skip_reason' => $figures['skip_reason'],
        ];
    }

    /** @throws ValidationException */
    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    private function day(string $date): string
    {
        return CarbonImmutable::parse($date)->format('M j, Y');
    }
}
