<?php

namespace App\Services\Savings\Interest;

use App\Http\Resources\Savings\SavingsUserRef;
use App\Models\Branch;
use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Models\SavingsInterestRunLine;
use App\Models\SavingsProduct;
use App\Models\User;
use App\Services\BranchAccess;
use App\Services\Savings\SavingsMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * How the interest-run endpoints answer (the frontend's `InterestRun`,
 * `InterestRunLine` and `InterestRunPreview`): money as two-decimal strings,
 * rates as four-decimal strings, users as `{id, name}` (null = the system),
 * and a member's name and code beside each line's account. Also the branch
 * scope every `{run}` route reads through: another branch's run is a 404.
 */
class RunPresenter
{
    public function __construct(private readonly BranchAccess $branches) {}

    /** @return Builder<SavingsInterestRun> */
    public function scope(User $user): Builder
    {
        return $this->branches->constrain(SavingsInterestRun::query(), $user, 'branch_id');
    }

    /** The run, or 404 when it doesn't exist or sits in a branch the user can't see. */
    public function find(User $user, int $id): SavingsInterestRun
    {
        return $this->scope($user)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    public function run(SavingsInterestRun $run, bool $withLines = false): array
    {
        $run->loadMissing(['product', 'branch', 'creator', 'checker', 'poster', 'reverser']);
        $checked = SavingsUserRef::from($run->checker);
        $rejected = $run->status === 'rejected';

        $data = [
            'id' => $run->id,
            'product' => $this->product($run->product),
            'product_id' => $run->product_id,
            'product_name' => $run->product?->name,
            'branch' => $this->branch($run->branch),
            'branch_id' => $run->branch_id,
            'branch_name' => $run->branch?->name,
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),
            'status' => $run->status,
            'account_count' => (int) $run->account_count,
            'total_gross' => $this->money($run->total_gross),
            'total_tax' => $this->money($run->total_tax),
            'total_net' => $this->money($run->total_net),
            'created_by' => SavingsUserRef::from($run->creator),
            'approved_by' => $rejected ? null : $checked,
            'approved_at' => $rejected ? null : $this->time($run->checked_at),
            'rejected_by' => $rejected ? $checked : null,
            'rejected_at' => $rejected ? $this->time($run->checked_at) : null,
            'reject_reason' => $run->rejection_reason,
            'posted_by' => SavingsUserRef::from($run->poster),
            'posted_at' => $this->time($run->posted_at),
            'reversed_by' => SavingsUserRef::from($run->reverser),
            'reversed_at' => $this->time($run->reversed_at),
            'reverse_reason' => $run->reversal_reason,
            'created_at' => $this->time($run->created_at),
        ];

        if ($withLines) {
            $lines = $run->lines()->with('account.borrower')->orderBy('id')->get();
            $data['lines'] = $lines->map(fn (SavingsInterestRunLine $line) => $this->line(RunBuilder::stored($line) + [
                'rate_segments' => $line->rate_segments ?? [],
                'interest_transaction_id' => $line->interest_transaction_id,
                'tax_transaction_id' => $line->tax_transaction_id,
            ], $line->account))->all();
        }

        return $data;
    }

    /**
     * A preview: one draft-to-be per branch, and the totals across them.
     *
     * @param  array{period_start: string, period_end: string, runs: list<array{branch_id: int, accounts: Collection<int, SavingsAccount>, lines: array<int, array<string, mixed>>}>}  $preview
     * @return array<string, mixed>
     */
    public function preview(SavingsProduct $product, array $preview): array
    {
        $branches = Branch::query()->whereIn('id', array_column($preview['runs'], 'branch_id'))->get()->keyBy('id');
        $all = ['account_count' => 0, 'gross' => 0, 'tax' => 0, 'net' => 0];
        $runs = [];

        foreach ($preview['runs'] as $run) {
            $totals = RunBuilder::totals($run['lines']);

            foreach ($all as $key => $value) {
                $all[$key] = $value + $totals[$key];
            }

            $runs[] = [
                'branch' => $this->branch($branches[$run['branch_id']] ?? null),
                'account_count' => $totals['account_count'],
                'total_gross' => SavingsMath::pesos($totals['gross']),
                'total_tax' => SavingsMath::pesos($totals['tax']),
                'total_net' => SavingsMath::pesos($totals['net']),
                'lines' => array_values(array_map(fn (array $line) => $this->line($line, $run['accounts'][$line['account_id']] ?? null), $run['lines'])),
            ];
        }

        return [
            'product' => $this->product($product),
            'period_start' => $preview['period_start'],
            'period_end' => $preview['period_end'],
            'runs' => $runs,
            'totals' => [
                'account_count' => $all['account_count'],
                'total_gross' => SavingsMath::pesos($all['gross']),
                'total_tax' => SavingsMath::pesos($all['tax']),
                'total_net' => SavingsMath::pesos($all['net']),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $line  centavos, as RunBuilder::figures() or ::stored()
     * @return array<string, mixed>
     */
    private function line(array $line, ?SavingsAccount $account): array
    {
        return [
            'account_id' => $line['account_id'],
            'account_number' => $account?->account_number,
            'borrower_id' => $account?->borrower_id,
            'borrower_name' => $account?->borrower?->full_name,
            'borrower_code' => $account?->borrower?->borrower_code,
            'days' => $line['days'],
            'balance_days' => $line['balance_days'],
            'average_daily_balance' => SavingsMath::pesos($line['average_daily_balance']),
            'rate' => $line['rate'],
            'rate_segments' => array_map(fn (array $segment) => [
                'from' => $segment['from'],
                'to' => $segment['to'],
                'rate' => $segment['rate'],
                ...$this->tiers($segment),
            ], $line['rate_segments'] ?? []),
            'gross_interest' => SavingsMath::pesos($line['gross']),
            'tax_rate' => $line['tax_rate'],
            'withholding_tax' => SavingsMath::pesos($line['tax']),
            'net_interest' => SavingsMath::pesos($line['net']),
            'skip_reason' => $line['skip_reason'],
            'interest_transaction_id' => $line['interest_transaction_id'] ?? null,
            'tax_transaction_id' => $line['tax_transaction_id'] ?? null,
        ];
    }

    /**
     * A tiered segment's ladder (`tiers`: min_balance, rate) and, per tier
     * the days used, its days and centavo-days (`by_tier`); nothing for a
     * single-rate segment, which reads as before tiers.
     *
     * @param  array<string, mixed>  $segment
     * @return array{tiers?: list<array{min_balance: string|null, rate: string}>, by_tier?: list<array{min_balance: string|null, rate: string, days: int, balance_days: int}>}
     */
    private function tiers(array $segment): array
    {
        if (! isset($segment['tiers'])) {
            return [];
        }

        return [
            'tiers' => array_map(fn (array $tier) => ['min_balance' => $tier['min_balance'], 'rate' => $tier['rate']], $segment['tiers']),
            'by_tier' => array_map(fn (array $tier) => [
                'min_balance' => $tier['min_balance'],
                'rate' => $tier['rate'],
                'days' => $tier['days'],
                'balance_days' => $tier['balance_days'],
            ], $segment['by_tier'] ?? []),
        ];
    }

    /** @return array{id: int, code: string, name: string}|null */
    private function product(?SavingsProduct $product): ?array
    {
        return $product === null ? null : ['id' => $product->id, 'code' => $product->code, 'name' => $product->name];
    }

    /** @return array{id: int, name: string}|null */
    private function branch(?Branch $branch): ?array
    {
        return $branch === null ? null : ['id' => $branch->id, 'name' => $branch->name];
    }

    private function money(mixed $pesos): string
    {
        return SavingsMath::pesos(SavingsMath::centavos($pesos));
    }

    private function time(?Carbon $time): ?string
    {
        return $time?->toJSON();
    }
}
