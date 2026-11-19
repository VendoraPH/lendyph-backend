<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The executive dashboard's alert thresholds (Phase 6 EB-C, decision 20): one
 * company-wide row, id 1, seeded by 2026_11_19_150300 with the approved
 * defaults. Read it through current().
 *
 * What each threshold means, for the evaluator (EB-D2) and the screen:
 * - `par30_rise_points`: PAR 30 is up this many percentage points or more on
 *   its value `par30_compare_days` days earlier (at least);
 * - `collection_efficiency_below`: Collection Efficiency is below this
 *   percentage (strictly);
 * - `overdue_share_above`: overdue is above this percentage of the portfolio
 *   (strictly);
 * - `approval_wait_days`: a loan has waited more than this many days for
 *   approval (strictly);
 * - `release_wait_days`: an approved loan is still not released after more
 *   than this many days (strictly);
 * - `control_difference_above`: a control check differs by more than this
 *   many pesos; ₱0.00 means any difference.
 *
 * Audited: each change writes one `updated` row with the stored old and new
 * values and a description in words (describeAuditEvent()); the Audit Trail
 * reads the values in words through valueInWords(). Wording stays neutral.
 *
 * @property int $id
 * @property string $par30_rise_points
 * @property int $par30_compare_days
 * @property string $collection_efficiency_below
 * @property string $overdue_share_above
 * @property int $approval_wait_days
 * @property int $release_wait_days
 * @property string $control_difference_above
 * @property int|null $updated_by set directly by the saver, never mass-assigned
 * @property Carbon|null $updated_at
 */
class DashboardSetting extends Model
{
    use Auditable;

    /** The one settings row's id, so a lost row is put back once, never twice. */
    public const ROW_ID = 1;

    public const DEFAULTS = [
        'par30_rise_points' => '1.00',
        'par30_compare_days' => 30,
        'collection_efficiency_below' => '90.00',
        'overdue_share_above' => '5.00',
        'approval_wait_days' => 3,
        'release_wait_days' => 2,
        'control_difference_above' => '0.00',
    ];

    /** Each threshold's name in words, in the screen's order. */
    public const LABELS = [
        'par30_rise_points' => 'PAR 30 rise',
        'par30_compare_days' => 'PAR 30 comparison period',
        'collection_efficiency_below' => 'Collection Efficiency threshold',
        'overdue_share_above' => 'Overdue share of the portfolio threshold',
        'approval_wait_days' => 'Approval waiting time',
        'release_wait_days' => 'Release waiting time',
        'control_difference_above' => 'Control difference tolerance',
    ];

    public const POINT_FIELDS = ['par30_rise_points'];

    public const PERCENT_FIELDS = ['collection_efficiency_below', 'overdue_share_above'];

    public const DAY_FIELDS = ['par30_compare_days', 'approval_wait_days', 'release_wait_days'];

    public const PESO_FIELDS = ['control_difference_above'];

    /** The highest control difference tolerance, in pesos. */
    public const MAX_CONTROL_DIFFERENCE = '1000000.00';

    protected $fillable = [
        'par30_rise_points',
        'par30_compare_days',
        'collection_efficiency_below',
        'overdue_share_above',
        'approval_wait_days',
        'release_wait_days',
        'control_difference_above',
    ];

    protected function casts(): array
    {
        return [
            'par30_rise_points' => 'decimal:2',
            'par30_compare_days' => 'integer',
            'collection_efficiency_below' => 'decimal:2',
            'overdue_share_above' => 'decimal:2',
            'approval_wait_days' => 'integer',
            'release_wait_days' => 'integer',
            'control_difference_above' => 'decimal:2',
            'updated_by' => 'integer',
        ];
    }

    /**
     * The settings row. If a database lost it, the defaults are put back as
     * row 1 (a second insert at the same moment is ignored), so there is
     * never more than one.
     */
    public static function current(): self
    {
        $row = static::query()->find(self::ROW_ID);

        if ($row !== null) {
            return $row;
        }

        DB::table('dashboard_settings')->insertOrIgnore([
            'id' => self::ROW_ID,
            ...self::DEFAULTS,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return static::query()->findOrFail(self::ROW_ID);
    }

    /**
     * Validated thresholds in the one form they are stored and audited in:
     * percentages, points and pesos as two-decimal strings ("085" reads
     * "85.00", ".5" reads "0.50"), worked on the digits so nothing is
     * rounded; days as whole numbers. Keys other than thresholds are dropped.
     *
     * @param  array<string, mixed>  $values  already validated (non-negative, at most two decimals)
     * @return array<string, string|int>
     */
    public static function normalised(array $values): array
    {
        $normalised = [];

        foreach (array_intersect_key($values, self::DEFAULTS) as $field => $value) {
            $normalised[$field] = in_array($field, self::DAY_FIELDS, true)
                ? (int) $value
                : self::twoDecimals((string) $value);
        }

        return $normalised;
    }

    private static function twoDecimals(string $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', ltrim(trim($value), '+-'), 2), 2, '');
        $whole = ltrim($whole, '0');

        return ($whole === '' ? '0' : $whole).'.'.str_pad($fraction, 2, '0');
    }

    /**
     * The thresholds as stored: percentages, points and pesos as two-decimal
     * strings, days as whole numbers.
     *
     * @return array<string, string|int>
     */
    public function thresholds(): array
    {
        return $this->only(array_keys(self::DEFAULTS));
    }

    /**
     * Each alert's rule in neutral words, with this row's values.
     *
     * @return list<array{key: string, text: string}>
     */
    public function rules(): array
    {
        $difference = (float) $this->control_difference_above > 0
            ? 'A control check shows a difference above '.self::valueInWords('control_difference_above', $this->control_difference_above).'.'
            : 'A control check shows any difference.';

        return [
            ['key' => 'par30_rise', 'text' => sprintf('PAR 30 is up %s or more on %s ago.', self::valueInWords('par30_rise_points', $this->par30_rise_points), self::valueInWords('par30_compare_days', $this->par30_compare_days))],
            ['key' => 'collection_efficiency', 'text' => sprintf('Collection Efficiency is below %s.', self::valueInWords('collection_efficiency_below', $this->collection_efficiency_below))],
            ['key' => 'overdue_share', 'text' => sprintf('Overdue is above %s of the portfolio.', self::valueInWords('overdue_share_above', $this->overdue_share_above))],
            ['key' => 'approval_wait', 'text' => sprintf('A loan has waited more than %s for approval.', self::valueInWords('approval_wait_days', $this->approval_wait_days))],
            ['key' => 'release_wait', 'text' => sprintf('An approved loan has not been released after more than %s.', self::valueInWords('release_wait_days', $this->release_wait_days))],
            ['key' => 'control_difference', 'text' => $difference],
        ];
    }

    /**
     * A threshold's value in words: "1.5 points", "90%" (never rounded),
     * "3 days", "₱250.00". Null for any other field or a value that isn't a
     * number, so the caller reads it its own way.
     */
    public static function valueInWords(string $field, mixed $value): ?string
    {
        if (is_bool($value) || ! is_numeric($value)) {
            return null;
        }

        $plain = (string) $value;
        $plain = str_contains($plain, '.') ? rtrim(rtrim($plain, '0'), '.') : $plain;

        return match (true) {
            in_array($field, self::POINT_FIELDS, true) => $plain.((float) $value === 1.0 ? ' point' : ' points'),
            in_array($field, self::PERCENT_FIELDS, true) => $plain.'%',
            in_array($field, self::DAY_FIELDS, true) => $plain.((int) $value === 1 ? ' day' : ' days'),
            in_array($field, self::PESO_FIELDS, true) => ((float) $value < 0 ? '-' : '').'₱'.number_format(abs((float) $value), 2),
            default => null,
        };
    }

    /**
     * The audit description of a change, in words: "Dashboard alert
     * thresholds changed: Approval waiting time from 3 days to 5 days; …".
     */
    public function describeAuditEvent(string $action): ?string
    {
        if ($action !== 'updated') {
            return null;
        }

        $parts = [];

        foreach (self::LABELS as $field => $label) {
            if (! $this->wasChanged($field)) {
                continue;
            }

            $parts[] = sprintf(
                '%s from %s to %s',
                $label,
                self::valueInWords($field, $this->getOriginal($field)),
                self::valueInWords($field, $this->getAttribute($field)),
            );
        }

        return $parts === [] ? null : 'Dashboard alert thresholds changed: '.implode('; ', $parts);
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
