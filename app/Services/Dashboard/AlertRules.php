<?php

namespace App\Services\Dashboard;

use App\Models\DashboardSetting;
use App\Models\Loan;
use App\Models\LoanApprovalStep;
use App\Models\User;
use App\Services\Accounting\BooksState;
use App\Services\Accounting\LoanAccountingSummary;
use App\Services\Accounting\Money;
use App\Services\Accounting\TrialBalanceBuilder;
use App\Services\ReportService;
use App\Services\Savings\Reports\GlControl;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The executive dashboard's alerts (Phase 6 EB-D2, served at
 * `/dashboard/executive/attention`): each of EB-C's thresholds
 * (DashboardSetting) evaluated on the figure of the report it comes from.
 * Nothing here works out a figure of its own; it compares.
 *
 * | Rule | Figure | Fires when |
 * |---|---|---|
 * | `par30_rise` | Portfolio Summary `par_30.ratio` today − the snapshot's `par_30.ratio` `par30_compare_days` ago (PortfolioHistory) | the rise is AT LEAST the points |
 * | `collection_efficiency` | Collection Efficiency `collection_rate` for the period | strictly below |
 * | `overdue_share` | Portfolio Summary `past_due_amount` ÷ `total_outstanding` × 100, to 2 decimals | strictly above |
 * | `approval_wait` | loans for approval, by Manila calendar days since their latest submission | any waited strictly more than the days |
 * | `release_wait` | approved loans, by Manila calendar days since `approved_at` | any waited strictly more than the days |
 * | `control_difference` | the trial balance, books vs loan records, the savings GL control and the share capital control | any difference strictly above the tolerance (₱0.00: any) |
 *
 * States: `needs_attention`, `clear`, `no_data` (nothing to measure),
 * `no_snapshot` (no earlier snapshot to compare with), `no_access` (the
 * caller can't read the rule's source; no figure is sent) and
 * `requires_configuration` (book controls before the opening balances are
 * finalised; BooksState).
 *
 * PAR's rise reads branch and product only, the filters history follows, so
 * both sides of the comparison cover the same loans. Balances are as of
 * today; the period applies to Collection Efficiency. Wording stays neutral.
 */
final class AlertRules
{
    public const NEEDS_ATTENTION = 'needs_attention';

    public const CLEAR = 'clear';

    public const NO_DATA = 'no_data';

    public const NO_SNAPSHOT = 'no_snapshot';

    public const NO_ACCESS = 'no_access';

    public const REQUIRES_CONFIGURATION = 'requires_configuration';

    /** Each rule's source permission. */
    public const PERMISSIONS = [
        'par30_rise' => ['reports:view'],
        'collection_efficiency' => ['reports:view'],
        'overdue_share' => ['reports:view'],
        'approval_wait' => ['loans:view'],
        'release_wait' => ['loans:view'],
        'control_difference' => ['accounting:view'],
    ];

    /** Each rule's name in words. */
    public const LABELS = [
        'par30_rise' => 'PAR 30 rise',
        'collection_efficiency' => 'Collection Efficiency',
        'overdue_share' => 'Overdue share of the portfolio',
        'approval_wait' => 'Loans waiting for approval',
        'release_wait' => 'Approved loans waiting for release',
        'control_difference' => 'Control checks',
    ];

    /** Where each rule's figure can be looked into. */
    public const LINKS = [
        'par30_rise' => '/loans/past-due',
        'collection_efficiency' => '/reports/collection_efficiency',
        'overdue_share' => '/loans/past-due',
        'approval_wait' => '/loans',
        'release_wait' => '/loans',
        'control_difference' => '/accounting/trial-balance',
    ];

    public function __construct(
        private ReportService $reports,
        private PortfolioHistory $history,
        private TrialBalanceBuilder $trialBalance,
        private LoanAccountingSummary $loanAccounting,
        private GlControl $savingsControl,
        private ShareCapitalControl $shareCapital,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  report filters: branch_ids (the caller's), branch_id, product_id, officer_id
     * @param  list<int>|null  $branchIds  the branches read: the caller's, narrowed by branch_id; null for every branch
     * @return list<array<string, mixed>>
     */
    public function evaluate(User $user, DashboardPeriod $period, array $filters, ?array $branchIds): array
    {
        $settings = DashboardSetting::current();
        $texts = collect($settings->rules())->pluck('text', 'key');
        $today = DashboardPeriod::today();
        $balanceFilters = array_diff_key($filters, ['date_from' => true, 'date_to' => true, 'collector_id' => true]);
        $summary = null;
        $summaryFor = function () use (&$summary, $balanceFilters): array {
            return $summary ??= $this->reports->loanBalanceSummary($balanceFilters);
        };

        $rules = [];

        foreach (array_keys(self::PERMISSIONS) as $key) {
            $base = [
                'key' => $key,
                'label' => self::LABELS[$key],
                'rule' => $texts[$key],
                'threshold' => $settings->thresholds()[self::thresholdField($key)],
                'link' => self::LINKS[$key],
            ];

            if (! $this->canRead($user, $key)) {
                $rules[] = [...$base, 'state' => self::NO_ACCESS, 'value' => null, 'title' => self::LABELS[$key], 'detail' => 'Your role can\'t view the figures behind this check.'];

                continue;
            }

            $rules[] = [...$base, ...match ($key) {
                'par30_rise' => $this->par30Rise($settings, $filters, $branchIds, $today),
                'collection_efficiency' => $this->collectionEfficiency($settings, $filters, $period),
                'overdue_share' => $this->overdueShare($settings, $summaryFor),
                'approval_wait' => $this->waiting($settings->approval_wait_days, $filters, $branchIds, $today, approval: true),
                'release_wait' => $this->waiting($settings->release_wait_days, $filters, $branchIds, $today, approval: false),
                'control_difference' => $this->controls($user, $settings, $branchIds, $today),
            }];
        }

        return $rules;
    }

    private function canRead(User $user, string $key): bool
    {
        foreach (self::PERMISSIONS[$key] as $permission) {
            if (! $user->can($permission)) {
                return false;
            }
        }

        return true;
    }

    private static function thresholdField(string $key): string
    {
        return match ($key) {
            'par30_rise' => 'par30_rise_points',
            'collection_efficiency' => 'collection_efficiency_below',
            'overdue_share' => 'overdue_share_above',
            'approval_wait' => 'approval_wait_days',
            'release_wait' => 'release_wait_days',
            'control_difference' => 'control_difference_above',
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    private function par30Rise(DashboardSetting $settings, array $filters, ?array $branchIds, CarbonImmutable $today): array
    {
        $productId = filled($filters['product_id'] ?? null) ? (int) $filters['product_id'] : null;
        $current = $this->reports->loanBalanceSummary(array_diff_key(
            $filters,
            ['date_from' => true, 'date_to' => true, 'officer_id' => true, 'collector_id' => true],
        ))['par_30']['ratio'];
        $date = $today->subDays($settings->par30_compare_days)->toDateString();
        $point = $this->history->points([$date], $branchIds, $productId)['points'][0];
        $earlier = $point['figures']['par_30']['ratio'] ?? null;

        if ($point['state'] !== 'ready' || $earlier === null) {
            return [
                'state' => self::NO_SNAPSHOT,
                'value' => null,
                'current' => $current,
                'compared_with' => ['requested_date' => $date, 'snapshot_date' => null, 'ratio' => null],
                'title' => 'PAR 30 has no earlier snapshot to compare with',
                'detail' => PortfolioHistory::NO_SNAPSHOT.' ('.self::dateInWords($date).').',
                'as_of' => $today->toDateString(),
            ];
        }

        $rise = round((float) $current - (float) $earlier, 2);
        $fires = self::hundredths($rise) >= self::hundredths($settings->par30_rise_points);

        return [
            'state' => $fires ? self::NEEDS_ATTENTION : self::CLEAR,
            'value' => $rise,
            'current' => $current,
            'compared_with' => ['requested_date' => $date, 'snapshot_date' => $point['snapshot_date'], 'ratio' => $earlier],
            'title' => sprintf('PAR 30 is %s%% today, %s%% on %s', self::number($current), self::number($earlier), self::dateInWords($point['snapshot_date'])),
            'detail' => sprintf('A change of %s %s.', self::number($rise), abs($rise) === 1.0 ? 'point' : 'points'),
            'as_of' => $today->toDateString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function collectionEfficiency(DashboardSetting $settings, array $filters, DashboardPeriod $period): array
    {
        $report = $this->reports->collectionEfficiency([
            ...$filters,
            'date_from' => $period->from->toDateString(),
            'date_to' => $period->to->toDateString(),
        ]);

        if ((float) $report['total_due'] <= 0) {
            return [
                'state' => self::NO_DATA,
                'value' => null,
                'title' => 'Nothing fell due in the period',
                'detail' => 'Collection Efficiency needs at least one period falling due.',
                'as_of' => $period->asOf->toDateString(),
            ];
        }

        $rate = (float) $report['collection_rate'];
        $fires = self::hundredths($rate) < self::hundredths($settings->collection_efficiency_below);

        return [
            'state' => $fires ? self::NEEDS_ATTENTION : self::CLEAR,
            'value' => $rate,
            'title' => sprintf('Collection Efficiency is %s%%', self::number($rate)),
            'detail' => sprintf('%s of what fell due in the period is still uncollected.', Money::format((int) round((float) $report['uncollected'] * 100))),
            'as_of' => $period->asOf->toDateString(),
        ];
    }

    /**
     * @param  callable(): array<string, mixed>  $summaryFor
     * @return array<string, mixed>
     */
    private function overdueShare(DashboardSetting $settings, callable $summaryFor): array
    {
        $summary = $summaryFor();
        $outstanding = (float) $summary['total_outstanding'];
        $today = DashboardPeriod::today()->toDateString();

        if ($outstanding <= 0) {
            return [
                'state' => self::NO_DATA,
                'value' => null,
                'title' => 'Nothing is owed on collectible loans',
                'detail' => 'The overdue share needs an outstanding balance.',
                'as_of' => $today,
            ];
        }

        $share = round((float) $summary['past_due_amount'] / $outstanding * 100, 2);
        $fires = self::hundredths($share) > self::hundredths($settings->overdue_share_above);

        return [
            'state' => $fires ? self::NEEDS_ATTENTION : self::CLEAR,
            'value' => $share,
            'title' => sprintf('Past due is %s%% of the outstanding balance', self::number($share)),
            'detail' => sprintf(
                '%s past due after grace, of %s outstanding.',
                Money::format((int) round((float) $summary['past_due_amount'] * 100)),
                Money::format((int) round($outstanding * 100)),
            ),
            'as_of' => $today,
        ];
    }

    /**
     * Loans for approval (since their latest submission) or approved and not
     * released (since approval), in Manila calendar days.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    private function waiting(int $days, array $filters, ?array $branchIds, CarbonImmutable $today, bool $approval): array
    {
        $loans = Loan::query()
            ->where('status', $approval ? 'for_review' : 'approved')
            ->when($branchIds !== null, fn (Builder $q) => $q->whereIn('loans.branch_id', $branchIds))
            ->tap(LoanScope::fromFilters($filters)->loans())
            ->when($approval, fn (Builder $q) => $q->with('approvalSteps'))
            ->get();

        $waited = [];
        $notMeasured = 0;

        foreach ($loans as $loan) {
            $since = $approval ? self::latestSubmission($loan) : $loan->approved_at;

            if ($since === null) {
                $notMeasured++;

                continue;
            }

            $waited[] = (int) CarbonImmutable::parse($since->toDateString(), DashboardPeriod::TIMEZONE)->diffInDays($today);
        }

        $over = array_values(array_filter($waited, fn (int $waitedDays): bool => $waitedDays > $days));
        $count = count($over);
        $what = $approval ? 'waiting for approval' : 'approved and not released';

        return [
            'state' => $count > 0 ? self::NEEDS_ATTENTION : self::CLEAR,
            'value' => $count,
            'oldest_days' => $waited === [] ? null : max($waited),
            'not_measured' => $notMeasured,
            'title' => $count > 0
                ? sprintf('%d %s %s for more than %s', $count, $count === 1 ? 'loan' : 'loans', $what, self::days($days))
                : sprintf('No loan %s for more than %s', $what, self::days($days)),
            'detail' => sprintf('%d %s %s in all.', count($loans), count($loans) === 1 ? 'loan' : 'loans', $what),
            'as_of' => $today->toDateString(),
        ];
    }

    /**
     * When a loan for approval was last submitted: the submit step of its
     * current round, else Loan::submittedAt() (the first round's, or the
     * audit row it falls back to).
     */
    private static function latestSubmission(Loan $loan): ?CarbonInterface
    {
        return LoanApprovalStep::currentRound($loan->approvalSteps)
            ->where('kind', LoanApprovalStep::KIND_SUBMIT)
            ->whereNotNull('acted_at')
            ->first()
            ?->acted_at
            ?? $loan->submittedAt(true);
    }

    /**
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    private function controls(User $user, DashboardSetting $settings, ?array $branchIds, CarbonImmutable $today): array
    {
        $books = BooksState::current();
        $asOf = $today->toDateString();

        if ($books['state'] !== BooksState::READY) {
            return [
                'state' => self::REQUIRES_CONFIGURATION,
                'value' => null,
                'controls' => [],
                'title' => $books['label'],
                'detail' => $books['reason'],
                'as_of' => $asOf,
            ];
        }

        $tolerance = self::hundredths($settings->control_difference_above);
        $controls = [];
        $add = function (string $key, string $label, int $difference) use (&$controls, $tolerance): void {
            $controls[] = [
                'key' => $key,
                'label' => $label,
                'difference' => self::pesos($difference),
                'state' => abs($difference) > $tolerance ? self::NEEDS_ATTENTION : self::CLEAR,
            ];
        };

        $add('trial_balance', 'Trial balance', $this->trialBalance->build($asOf, $branchIds)['difference']);
        $add('books_vs_records', 'Loans receivable against the loan records', $this->loanAccounting->build($asOf, $branchIds)['books_vs_records']['difference']);

        if ($this->savingsControl->hasSavings()) {
            $savings = $this->savingsControl->build($asOf, $branchIds, null, true);
            $add('savings', 'Savings against the books', self::largest(array_map(
                fn (array $row): int => self::signedCentavos((string) $row['difference']),
                $savings['rows'],
            )));
        }

        if ($user->can('share_capital:view')) {
            $shareCapital = $this->shareCapital->build($asOf, $branchIds);

            if ($shareCapital['account'] !== null) {
                $add('share_capital', 'Share capital against the books', self::largest(array_column($shareCapital['rows'], 'difference')));
            }
        }

        $largest = self::largest(array_map(fn (array $control): int => self::signedCentavos($control['difference']), $controls));
        $flagged = array_values(array_filter($controls, fn (array $control): bool => $control['state'] === self::NEEDS_ATTENTION));

        return [
            'state' => $flagged === [] ? self::CLEAR : self::NEEDS_ATTENTION,
            'value' => self::pesos(abs($largest)),
            'controls' => $controls,
            'title' => $flagged === []
                ? 'The control checks agree'
                : sprintf('%d control %s %s a difference', count($flagged), count($flagged) === 1 ? 'check' : 'checks', count($flagged) === 1 ? 'shows' : 'show'),
            'detail' => $flagged === []
                ? sprintf('%d control %s compared.', count($controls), count($controls) === 1 ? 'check' : 'checks')
                : 'Look into: '.implode(', ', array_column($flagged, 'label')).'.',
            'as_of' => $asOf,
        ];
    }

    /**
     * The value furthest from zero, keeping its sign; 0 for none.
     *
     * @param  list<int|null>  $values
     */
    private static function largest(array $values): int
    {
        $largest = 0;

        foreach ($values as $value) {
            if ($value !== null && abs($value) > abs($largest)) {
                $largest = $value;
            }
        }

        return $largest;
    }

    /** A two-decimal value as whole hundredths, worked on its digits. */
    private static function hundredths(string|float|int $value): int
    {
        return self::signedCentavos(is_float($value) ? number_format($value, 2, '.', '') : (string) $value);
    }

    /** "-12.50" → -1250. */
    private static function signedCentavos(string $value): int
    {
        $negative = str_starts_with(trim($value), '-');
        $centavos = Money::toCentavos(ltrim(trim($value), '-')) ?? 0;

        return $negative ? -$centavos : $centavos;
    }

    /** Centavos as a two-decimal peso string, "-0.50". */
    private static function pesos(int $centavos): string
    {
        return ($centavos < 0 ? '-' : '').intdiv(abs($centavos), 100).'.'.str_pad((string) (abs($centavos) % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function number(float|int|string $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.');
    }

    private static function days(int $days): string
    {
        return $days === 1 ? '1 day' : "{$days} days";
    }

    private static function dateInWords(string $date): string
    {
        return CarbonImmutable::parse($date)->format('M j, Y');
    }
}
