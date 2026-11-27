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
 * Two phases, so the figures can be cached by scope and filters
 * (ExecutiveCacheKey) while every caller's permissions and the current
 * thresholds are applied after the cache: self::figures() reads the reports,
 * self::evaluate() compares and redacts.
 *
 * - One Portfolio Summary serves PAR 30 and the overdue share. PAR's rise
 *   compares with history, which follows branch and product only, so with an
 *   officer filter it reads `no_data` rather than compare different loans.
 * - A loan sent back to its submit step is with its submitter, not waiting
 *   for approval, so it is left out of `approval_wait`.
 * - Control checks: the trial balance always; the savings control with
 *   `savings:view` while savings is in use; books against loan records and the
 *   share capital control (its totals, the rows being the drill-down) only
 *   once the books are ready (BooksState), the latter with
 *   `share_capital:view`. A check skipped for access reads `no_access`.
 *
 * Balances are as of today; the period applies to Collection Efficiency.
 * Wording stays neutral.
 */
final class AlertRules
{
    public const NEEDS_ATTENTION = 'needs_attention';

    public const CLEAR = 'clear';

    public const NO_DATA = 'no_data';

    public const NO_SNAPSHOT = 'no_snapshot';

    public const NO_ACCESS = 'no_access';

    public const REQUIRES_CONFIGURATION = 'requires_configuration';

    public const NO_ACCESS_DETAIL = 'Your role can\'t view the figures behind this check.';

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
        'overdue_share' => 'Overdue share of the outstanding balance',
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
     * Every rule's figures for a scope, filters and period, whoever asks:
     * plain arrays, safe to cache. No threshold and no permission is applied.
     *
     * @param  array<string, mixed>  $filters  report filters: branch_ids (the caller's), branch_id, product_id, officer_id, collector_id
     * @param  list<int>|null  $branchIds  the branches read: the caller's, narrowed by branch_id; null for every branch
     * @return array<string, mixed>
     */
    public function figures(DashboardPeriod $period, array $filters, ?array $branchIds, int $compareDays): array
    {
        $today = DashboardPeriod::today();
        $summary = $this->reports->loanBalanceSummary(
            array_diff_key($filters, ['date_from' => true, 'date_to' => true, 'collector_id' => true]),
        );
        $efficiency = $this->reports->collectionEfficiency([
            ...$filters,
            'date_from' => $period->from->toDateString(),
            'date_to' => $period->to->toDateString(),
        ]);

        return [
            'today' => $today->toDateString(),
            'period_as_of' => $period->asOf->toDateString(),
            'par30_rise' => $this->par30Figures($summary, $filters, $branchIds, $today, $compareDays),
            'collection_efficiency' => [
                'total_due' => (float) $efficiency['total_due'],
                'rate' => (float) $efficiency['collection_rate'],
                'uncollected' => (float) $efficiency['uncollected'],
            ],
            'overdue_share' => [
                'past_due_amount' => (float) $summary['past_due_amount'],
                'total_outstanding' => (float) $summary['total_outstanding'],
            ],
            'approval_wait' => $this->waitingFigures($filters, $branchIds, $today, approval: true),
            'release_wait' => $this->waitingFigures($filters, $branchIds, $today, approval: false),
            'control_difference' => $this->controlFigures($branchIds, $today->toDateString()),
        ];
    }

    /**
     * Each rule for `$user`, in the settings' order: its source's figures
     * compared with the current thresholds, or `no_access` with no figure.
     *
     * @param  array<string, mixed>  $figures  self::figures()
     * @return list<array<string, mixed>>
     */
    public function evaluate(User $user, array $figures): array
    {
        $settings = DashboardSetting::current();
        $texts = collect($settings->rules())->pluck('text', 'key');
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
                $rules[] = [...$base, 'state' => self::NO_ACCESS, 'value' => null, 'title' => self::LABELS[$key], 'detail' => self::NO_ACCESS_DETAIL];

                continue;
            }

            $rules[] = [...$base, ...match ($key) {
                'par30_rise' => $this->par30Rise($settings, $figures['par30_rise'], $figures['today']),
                'collection_efficiency' => $this->collectionEfficiency($settings, $figures['collection_efficiency'], $figures['period_as_of']),
                'overdue_share' => $this->overdueShare($settings, $figures['overdue_share'], $figures['today']),
                'approval_wait' => $this->waiting($settings->approval_wait_days, $figures['approval_wait'], $figures['today'], approval: true),
                'release_wait' => $this->waiting($settings->release_wait_days, $figures['release_wait'], $figures['today'], approval: false),
                'control_difference' => $this->controls($user, $settings, $figures['control_difference'], $figures['today']),
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
     * @param  array<string, mixed>  $summary  the Portfolio Summary for the scope and filters
     * @param  array<string, mixed>  $filters
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    private function par30Figures(array $summary, array $filters, ?array $branchIds, CarbonImmutable $today, int $compareDays): array
    {
        $date = $today->subDays($compareDays)->toDateString();

        if (filled($filters['officer_id'] ?? null)) {
            return ['officer_filtered' => true, 'current' => null, 'requested_date' => $date, 'snapshot_date' => null, 'earlier' => null];
        }

        $productId = filled($filters['product_id'] ?? null) ? (int) $filters['product_id'] : null;
        $point = $this->history->points([$date], $branchIds, $productId)['points'][0];

        return [
            'officer_filtered' => false,
            'current' => (float) $summary['par_30']['ratio'],
            'requested_date' => $date,
            'snapshot_date' => $point['state'] === 'ready' ? $point['snapshot_date'] : null,
            'earlier' => $point['state'] === 'ready' && isset($point['figures']['par_30']['ratio']) ? (float) $point['figures']['par_30']['ratio'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $figures
     * @return array<string, mixed>
     */
    private function par30Rise(DashboardSetting $settings, array $figures, string $today): array
    {
        if ($figures['officer_filtered']) {
            return [
                'state' => self::NO_DATA,
                'value' => null,
                'current' => null,
                'compared_with' => ['requested_date' => $figures['requested_date'], 'snapshot_date' => null, 'ratio' => null],
                'title' => 'PAR 30 history isn\'t kept by account officer',
                'detail' => 'Clear the officer filter to compare PAR 30 with an earlier snapshot.',
                'as_of' => $today,
            ];
        }

        if ($figures['earlier'] === null) {
            return [
                'state' => self::NO_SNAPSHOT,
                'value' => null,
                'current' => $figures['current'],
                'compared_with' => ['requested_date' => $figures['requested_date'], 'snapshot_date' => null, 'ratio' => null],
                'title' => 'PAR 30 has no earlier snapshot to compare with',
                'detail' => PortfolioHistory::NO_SNAPSHOT.' ('.self::dateInWords($figures['requested_date']).').',
                'as_of' => $today,
            ];
        }

        $rise = round($figures['current'] - $figures['earlier'], 2);
        $fires = self::hundredths($rise) >= self::hundredths($settings->par30_rise_points);

        return [
            'state' => $fires ? self::NEEDS_ATTENTION : self::CLEAR,
            'value' => $rise,
            'current' => $figures['current'],
            'compared_with' => ['requested_date' => $figures['requested_date'], 'snapshot_date' => $figures['snapshot_date'], 'ratio' => $figures['earlier']],
            'title' => sprintf('PAR 30 is %s%% today, %s%% on %s', self::number($figures['current']), self::number($figures['earlier']), self::dateInWords($figures['snapshot_date'])),
            'detail' => sprintf('A change of %s %s.', self::number($rise), abs($rise) === 1.0 ? 'point' : 'points'),
            'as_of' => $today,
        ];
    }

    /**
     * @param  array{total_due: float, rate: float, uncollected: float}  $figures
     * @return array<string, mixed>
     */
    private function collectionEfficiency(DashboardSetting $settings, array $figures, string $asOf): array
    {
        if ($figures['total_due'] <= 0) {
            return [
                'state' => self::NO_DATA,
                'value' => null,
                'title' => 'Nothing fell due in the period',
                'detail' => 'Collection Efficiency needs at least one period falling due.',
                'as_of' => $asOf,
            ];
        }

        $fires = self::hundredths($figures['rate']) < self::hundredths($settings->collection_efficiency_below);

        return [
            'state' => $fires ? self::NEEDS_ATTENTION : self::CLEAR,
            'value' => $figures['rate'],
            'title' => sprintf('Collection Efficiency is %s%%', self::number($figures['rate'])),
            'detail' => sprintf('%s of what fell due in the period is still uncollected.', Money::format((int) round($figures['uncollected'] * 100))),
            'as_of' => $asOf,
        ];
    }

    /**
     * @param  array{past_due_amount: float, total_outstanding: float}  $figures
     * @return array<string, mixed>
     */
    private function overdueShare(DashboardSetting $settings, array $figures, string $today): array
    {
        if ($figures['total_outstanding'] <= 0) {
            return [
                'state' => self::NO_DATA,
                'value' => null,
                'title' => 'Nothing is owed on collectible loans',
                'detail' => 'The overdue share needs an outstanding balance.',
                'as_of' => $today,
            ];
        }

        $share = round($figures['past_due_amount'] / $figures['total_outstanding'] * 100, 2);
        $fires = self::hundredths($share) > self::hundredths($settings->overdue_share_above);

        return [
            'state' => $fires ? self::NEEDS_ATTENTION : self::CLEAR,
            'value' => $share,
            'title' => sprintf('Past due is %s%% of the outstanding balance', self::number($share)),
            'detail' => sprintf(
                '%s past due after grace, of %s outstanding.',
                Money::format((int) round($figures['past_due_amount'] * 100)),
                Money::format((int) round($figures['total_outstanding'] * 100)),
            ),
            'as_of' => $today,
        ];
    }

    /**
     * Loans for approval (since their latest submission; one sent back to
     * its submit step is with its submitter and is left out) or approved and
     * not released (since approval), as Manila calendar days waited.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<int>|null  $branchIds
     * @return array{waited: list<int>, not_measured: int, returned_to_submitter: int}
     */
    private function waitingFigures(array $filters, ?array $branchIds, CarbonImmutable $today, bool $approval): array
    {
        $loans = Loan::query()
            ->where('status', $approval ? 'for_review' : 'approved')
            ->when($branchIds !== null, fn (Builder $q) => $q->whereIn('loans.branch_id', $branchIds))
            ->tap(LoanScope::fromFilters($filters)->loans())
            ->when($approval, fn (Builder $q) => $q->with('approvalSteps'))
            ->get();

        $waited = [];
        $notMeasured = 0;
        $returned = 0;

        foreach ($loans as $loan) {
            if ($approval && self::withSubmitter($loan)) {
                $returned++;

                continue;
            }

            $since = $approval ? self::latestSubmission($loan) : $loan->approved_at;

            if ($since === null) {
                $notMeasured++;

                continue;
            }

            $waited[] = (int) CarbonImmutable::parse($since->toDateString(), DashboardPeriod::TIMEZONE)->diffInDays($today);
        }

        return ['waited' => $waited, 'not_measured' => $notMeasured, 'returned_to_submitter' => $returned];
    }

    /**
     * @param  array{waited: list<int>, not_measured: int, returned_to_submitter: int}  $figures
     * @return array<string, mixed>
     */
    private function waiting(int $days, array $figures, string $today, bool $approval): array
    {
        $waited = $figures['waited'];
        $count = count(array_filter($waited, fn (int $waitedDays): bool => $waitedDays > $days));
        $total = count($waited) + $figures['not_measured'];
        $what = $approval ? 'waiting for approval' : 'approved and not released';

        return [
            'state' => $count > 0 ? self::NEEDS_ATTENTION : self::CLEAR,
            'value' => $count,
            'oldest_days' => $waited === [] ? null : max($waited),
            'not_measured' => $figures['not_measured'],
            ...($approval ? ['returned_to_submitter' => $figures['returned_to_submitter']] : []),
            'title' => $count > 0
                ? sprintf('%d %s %s for more than %s', $count, $count === 1 ? 'loan' : 'loans', $what, self::days($days))
                : sprintf('No loan %s for more than %s', $what, self::days($days)),
            'detail' => sprintf('%d %s %s in all.', $total, $total === 1 ? 'loan' : 'loans', $what),
            'as_of' => $today,
        ];
    }

    /** A loan for approval whose pending step is the submit step: sent back to its submitter. */
    private static function withSubmitter(Loan $loan): bool
    {
        return LoanApprovalStep::currentRound($loan->approvalSteps)
            ->firstWhere('status', LoanApprovalStep::STATUS_PENDING)
            ?->kind === LoanApprovalStep::KIND_SUBMIT;
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
     * Each control's difference in centavos (books against loan records and
     * share capital null until the books are ready; savings null while
     * savings isn't in use; share capital null with no share capital account).
     *
     * @param  list<int>|null  $branchIds
     * @return array{books_state: array{state: string, label: string, reason: string|null}, trial_balance: int, books_vs_records: int|null, savings: int|null, share_capital: int|null}
     */
    private function controlFigures(?array $branchIds, string $asOf): array
    {
        $books = BooksState::current();
        $ready = $books['state'] === BooksState::READY;
        $savings = null;

        if ($this->savingsControl->hasSavings()) {
            $savings = self::largest(array_map(
                fn (array $row): int => self::signedCentavos((string) $row['difference']),
                $this->savingsControl->build($asOf, $branchIds, null, true)['rows'],
            ));
        }

        return [
            'books_state' => $books,
            'trial_balance' => $this->trialBalance->build($asOf, $branchIds)['difference'],
            'books_vs_records' => $ready ? $this->loanAccounting->build($asOf, $branchIds)['books_vs_records']['difference'] : null,
            'savings' => $savings,
            'share_capital' => $ready ? $this->shareCapital->build($asOf, $branchIds)['totals']['difference'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $figures  self::controlFigures()
     * @return array<string, mixed>
     */
    private function controls(User $user, DashboardSetting $settings, array $figures, string $asOf): array
    {
        $books = $figures['books_state'];
        $ready = $books['state'] === BooksState::READY;
        $tolerance = self::hundredths($settings->control_difference_above);
        $controls = [];
        $row = function (string $key, string $label, string $state, ?int $difference = null) use (&$controls, $tolerance): void {
            if ($state === self::CLEAR && $difference !== null && abs($difference) > $tolerance) {
                $state = self::NEEDS_ATTENTION;
            }

            $controls[] = ['key' => $key, 'label' => $label, 'difference' => $difference === null ? null : self::pesos($difference), 'state' => $state];
        };

        $row('trial_balance', 'Trial balance', self::CLEAR, $figures['trial_balance']);
        $row('books_vs_records', 'Loans receivable against the loan records', $ready ? self::CLEAR : self::REQUIRES_CONFIGURATION, $figures['books_vs_records']);

        if ($figures['savings'] !== null) {
            $row('savings', 'Savings against the books', $user->can('savings:view') ? self::CLEAR : self::NO_ACCESS, $user->can('savings:view') ? $figures['savings'] : null);
        }

        $shareCapitalState = match (true) {
            ! $user->can('share_capital:view') => self::NO_ACCESS,
            ! $ready => self::REQUIRES_CONFIGURATION,
            default => self::CLEAR,
        };
        if ($shareCapitalState !== self::CLEAR || $figures['share_capital'] !== null) {
            $row('share_capital', 'Share capital against the books', $shareCapitalState, $shareCapitalState === self::CLEAR ? $figures['share_capital'] : null);
        }

        $states = array_column($controls, 'state');
        $flagged = array_values(array_filter($controls, fn (array $control): bool => $control['state'] === self::NEEDS_ATTENTION));
        $measured = array_values(array_filter($controls, fn (array $control): bool => $control['difference'] !== null));
        $largest = self::largest(array_map(fn (array $control): int => self::signedCentavos($control['difference']), $measured));

        $state = match (true) {
            $flagged !== [] => self::NEEDS_ATTENTION,
            in_array(self::REQUIRES_CONFIGURATION, $states, true) => self::REQUIRES_CONFIGURATION,
            default => self::CLEAR,
        };

        return [
            'state' => $state,
            'value' => $measured === [] ? null : self::pesos(abs($largest)),
            'controls' => $controls,
            'title' => match ($state) {
                self::NEEDS_ATTENTION => sprintf('%d control %s %s a difference', count($flagged), count($flagged) === 1 ? 'check' : 'checks', count($flagged) === 1 ? 'shows' : 'show'),
                self::REQUIRES_CONFIGURATION => $books['label'],
                default => 'The control checks agree',
            },
            'detail' => match ($state) {
                self::NEEDS_ATTENTION => 'Look into: '.implode(', ', array_column($flagged, 'label')).'.',
                self::REQUIRES_CONFIGURATION => $books['reason'],
                default => sprintf('%d control %s compared.', count($measured), count($measured) === 1 ? 'check' : 'checks'),
            },
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
