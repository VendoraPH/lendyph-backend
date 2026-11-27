<?php

namespace App\Services\CreditScoring\Api;

use App\Models\CreditScorecardRevision;
use App\Models\CreditScoringSetting;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\CreditScoring\Decimal;
use App\Services\CreditScoring\FactorTable;
use App\Services\CreditScoring\Scorer;
use Illuminate\Support\Facades\DB;

/**
 * The Scorecard Configuration screen's reads and saves.
 *
 * The screen may change the category weights, the risk bands' minimum
 * scores and each factor's ranges: its bands (threshold and points), its
 * `otherwise` points and, on a factor that has them, its default points.
 * A factor's id, rule, category, label, own points and switch stay as the
 * revision has them, since a category's weight is spread over its factors'
 * points; labels, descriptions and policy rules are the revision's own too
 * (UpdateScorecardConfigRequest checks all of this).
 *
 * A save that changes something writes a new immutable revision (the next
 * minor version, unvalidated) and puts it in use. The revision's `created`
 * audit row says in words what changed (weights, risk bands, factor ranges
 * as "95% or more: 15 points"), and the settings row audits itself. Nothing
 * is recalculated here: new scores use the revision from the next run, and
 * every old score keeps its revision stamp.
 */
final class ScorecardEditor
{
    public const TOTAL_HUNDREDTHS = 10_000;

    /** Factors whose value is 1 for yes and 0 for no. */
    public const YES_NO_FACTORS = ['valid_id', 'valid_mobile', 'income_verified'];

    /**
     * Each factor's unit, singular and plural, as its ranges read in words.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const FACTOR_UNITS = [
        'on_time_rate' => ['%', '%'],
        'worst_days_late' => [' day', ' days'],
        'days_past_due' => [' day', ' days'],
        'defaults_restructures' => [' restructured loan', ' restructured loans'],
        'debt_service_ratio' => ['% of monthly income', '% of monthly income'],
        'payment_cover' => [' time', ' times'],
        'past_due_amount' => ['% of the amount outstanding', '% of the amount outstanding'],
        'active_loans' => [' active loan', ' active loans'],
        'debt_to_income' => ['% of annual income', '% of annual income'],
        'tenure' => [' year', ' years'],
        'residence' => [' year', ' years'],
        'membership_tenure' => [' year', ' years'],
        'completed_loans' => [' completed loan', ' completed loans'],
        'clean_completed_loans' => [' loan', ' loans'],
        'verified_references' => [' verified reference', ' verified references'],
    ];

    /**
     * The `ScorecardConfig` shape, plus `risk_bands`, `version` and
     * `validated` (tested or reviewed, CreditScorecardRevision::isChecked()).
     *
     * @return array<string, mixed>
     */
    public function config(?CreditScorecardRevision $revision = null): array
    {
        $revision ??= $this->current();

        return [
            'version' => $revision->version,
            'validated' => $revision->isChecked(),
            'categories' => array_map(fn (array $c) => [
                'key' => $c['key'],
                'label' => $c['label'],
                'weight_percent' => $c['weight_percent'],
                'description' => $c['description'] ?? '',
            ], array_values($revision->categories ?? [])),
            'factor_rules' => self::factorRules($revision),
            'policy_rules' => self::policyRules($revision),
            'risk_bands' => array_map(fn (array $b) => [
                'key' => $b['key'],
                'label' => $b['label'],
                'min_score' => (int) $b['min_score'],
            ], array_values($revision->risk_bands ?? [])),
        ];
    }

    public function current(): CreditScorecardRevision
    {
        return CreditScoringSetting::current()->currentRevision()->firstOrFail();
    }

    /**
     * The revision's factors as the screen lists them, with their ranges:
     * `rule` (at_least: the first band whose threshold the value reaches;
     * at_most: the first whose threshold it doesn't exceed), `bands`,
     * `otherwise` (points for a value no band takes), `default_points` on a
     * factor that has them (a defaulted or written-off loan), and `unit` in
     * words.
     *
     * @return list<array<string, mixed>>
     */
    public static function factorRules(CreditScorecardRevision $revision): array
    {
        return array_map(fn (array $f) => [
            'id' => (int) $f['id'],
            'category_key' => (string) $f['category_key'],
            'label' => (string) $f['label'],
            'points' => (int) $f['points'],
            'is_active' => (bool) ($f['is_active'] ?? true),
            'rule' => (string) $f['rule'],
            'bands' => array_map(fn (array $band) => [
                'threshold' => self::threshold($band['threshold']),
                'points' => (int) $band['points'],
            ], array_values($f['bands'] ?? [])),
            'otherwise' => (int) ($f['otherwise'] ?? 0),
            ...(array_key_exists('default_points', $f) ? ['default_points' => (int) $f['default_points']] : []),
            'unit' => self::unitLabel((string) ($f['key'] ?? '')),
        ], array_values($revision->factors ?? []));
    }

    /**
     * A threshold as stored: whole numbers as ints, others as the float of
     * their at most two decimals.
     */
    public static function threshold(int|float|string $value): int|float
    {
        return Decimal::unscaled(Decimal::scaled($value, 2), 2);
    }

    /**
     * The revision's policy rules as the screen lists them.
     *
     * @return list<array{id: int, label: string, description: string, is_active: bool}>
     */
    public static function policyRules(CreditScorecardRevision $revision): array
    {
        return array_map(fn (array $r) => [
            'id' => (int) $r['id'],
            'label' => (string) $r['label'],
            'description' => (string) $r['description'],
            'is_active' => (bool) ($r['is_active'] ?? true),
        ], array_values($revision->policy_rules ?? []));
    }

    /**
     * Save validated weights (key => weight) and, when sent, band minimums
     * (key => min_score) and factor ranges (factor id => any of `bands`,
     * `otherwise`, `default_points`). Returns the revision in use afterwards.
     *
     * @param  array<string, int|float|string>  $weights
     * @param  array<string, int>|null  $bandMinimums
     * @param  array<int, array{bands?: list<array{threshold: int|float|string, points: int|string}>, otherwise?: int|string, default_points?: int|string}>|null  $factorRanges
     */
    public function save(User $user, array $weights, ?array $bandMinimums, ?array $factorRanges = null): CreditScorecardRevision
    {
        return DB::transaction(function () use ($user, $weights, $bandMinimums, $factorRanges) {
            $settings = CreditScoringSetting::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $current = $settings->currentRevision()->firstOrFail();

            $categories = array_map(function (array $category) use ($weights) {
                $hundredths = FactorTable::hundredths($weights[$category['key']] ?? $category['weight_percent']);

                return array_merge($category, ['weight_percent' => Decimal::unscaled($hundredths, 2)]);
            }, array_values($current->categories));

            $bands = array_map(fn (array $band) => array_merge($band, [
                'min_score' => (int) ($bandMinimums[$band['key']] ?? $band['min_score']),
            ]), array_values($current->risk_bands));

            $factors = array_map(
                fn (array $factor) => self::withRanges($factor, $factorRanges[(int) $factor['id']] ?? []),
                array_values($current->factors),
            );

            $changes = $this->changes($current, $categories, $bands, $factors);

            if ($changes === []) {
                return $current;
            }

            $revision = AuditLogService::withoutModelAuditing(fn () => CreditScorecardRevision::query()->create([
                'version' => $this->nextVersion($current->version),
                'engine_version' => Scorer::ENGINE_VERSION,
                'categories' => $categories,
                'factors' => $factors,
                'risk_bands' => $bands,
                'policy_rules' => $current->policy_rules,
                'validated' => false,
                'notes' => "Saved from Scorecard Configuration, from revision {$current->version}. Unvalidated: not yet tested against the cooperative's own loan outcomes.",
                'created_by' => $user->id,
            ]));

            AuditLogService::log(
                'created',
                $revision,
                null,
                $revision->getAttributes(),
                "Scorecard {$revision->version} saved from {$current->version}. ".implode(' ', $changes),
            );

            $settings->update(['current_revision_id' => $revision->id, 'updated_by' => $user->id]);

            return $revision;
        });
    }

    /**
     * What a save changes, in words, one sentence per part (weights, risk
     * bands, factor ranges); empty when nothing changed.
     *
     * @param  list<array<string, mixed>>  $categories
     * @param  list<array<string, mixed>>  $bands
     * @param  list<array<string, mixed>>  $factors
     * @return list<string>
     */
    private function changes(CreditScorecardRevision $current, array $categories, array $bands, array $factors): array
    {
        $oldCategories = array_column(array_values($current->categories), null, 'key');
        $weights = [];

        foreach ($categories as $category) {
            $from = FactorTable::hundredths($oldCategories[$category['key']]['weight_percent']);
            $to = FactorTable::hundredths($category['weight_percent']);

            if ($from !== $to) {
                $weights[] = sprintf('%s %s%% to %s%%', $category['label'], Decimal::text($from, 2), Decimal::text($to, 2));
            }
        }

        $oldBands = array_column(array_values($current->risk_bands), null, 'key');
        $riskBands = [];

        foreach ($bands as $band) {
            $from = (int) $oldBands[$band['key']]['min_score'];

            if ($from !== (int) $band['min_score']) {
                $riskBands[] = sprintf('%s from %d to %d', $band['label'], $from, (int) $band['min_score']);
            }
        }

        $oldFactors = array_column(array_values($current->factors), null, 'id');
        $ranges = [];

        foreach ($factors as $factor) {
            if (self::rangesOf($factor) !== self::rangesOf($oldFactors[$factor['id']])) {
                $ranges[] = $factor['label'].': '.self::rangesText($factor).'.';
            }
        }

        return array_values(array_filter([
            $weights === [] ? null : 'Weights: '.implode('; ', $weights).'.',
            $riskBands === [] ? null : 'Risk bands: '.implode('; ', $riskBands).'.',
            $ranges === [] ? null : 'Factor ranges: '.implode(' ', $ranges),
        ]));
    }

    /**
     * A stored factor with the sent ranges in place of its own.
     *
     * @param  array<string, mixed>  $factor
     * @param  array{bands?: list<array{threshold: int|float|string, points: int|string}>, otherwise?: int|string, default_points?: int|string}  $ranges
     * @return array<string, mixed>
     */
    private static function withRanges(array $factor, array $ranges): array
    {
        if (array_key_exists('bands', $ranges)) {
            $factor['bands'] = array_map(fn (array $band) => [
                'threshold' => self::threshold($band['threshold']),
                'points' => (int) $band['points'],
            ], array_values($ranges['bands']));
        }

        foreach (['otherwise', 'default_points'] as $field) {
            if (array_key_exists($field, $ranges)) {
                $factor[$field] = (int) $ranges[$field];
            }
        }

        return $factor;
    }

    /**
     * A factor's ranges in a comparable form: thresholds in hundredths.
     *
     * @param  array<string, mixed>  $factor
     * @return array{bands: list<array{0: int, 1: int}>, otherwise: int, default_points: int|null}
     */
    private static function rangesOf(array $factor): array
    {
        return [
            'bands' => array_map(fn (array $band) => [Decimal::scaled($band['threshold'], 2), (int) $band['points']], array_values($factor['bands'] ?? [])),
            'otherwise' => (int) ($factor['otherwise'] ?? 0),
            'default_points' => array_key_exists('default_points', $factor) ? (int) $factor['default_points'] : null,
        ];
    }

    /**
     * A factor's ranges in words, in the order they're tried: "a defaulted
     * or written-off loan: 0 points; 95% or more: 15 points; otherwise: 0
     * points".
     *
     * @param  array<string, mixed>  $factor
     */
    private static function rangesText(array $factor): string
    {
        $key = (string) ($factor['key'] ?? '');
        $parts = array_key_exists('default_points', $factor)
            ? ['a defaulted or written-off loan: '.self::points((int) $factor['default_points'])]
            : [];

        foreach ($factor['bands'] ?? [] as $band) {
            $parts[] = self::bandText($key, (string) $factor['rule'], $band['threshold']).': '.self::points((int) $band['points']);
        }

        $parts[] = 'otherwise: '.self::points((int) ($factor['otherwise'] ?? 0));

        return implode('; ', $parts);
    }

    private static function bandText(string $key, string $rule, int|float|string $threshold): string
    {
        $value = self::threshold($threshold);

        if (in_array($key, self::YES_NO_FACTORS, true) && $rule === 'at_least' && $value >= 1) {
            return 'yes';
        }

        [$one, $many] = self::FACTOR_UNITS[$key] ?? ['', ''];

        return $value.((float) $value === 1.0 ? $one : $many).($rule === 'at_least' ? ' or more' : ' or less');
    }

    private static function points(int $points): string
    {
        return $points === 1 ? '1 point' : "{$points} points";
    }

    private static function unitLabel(string $key): string
    {
        if (in_array($key, self::YES_NO_FACTORS, true)) {
            return 'yes (1) or no (0)';
        }

        return trim(self::FACTOR_UNITS[$key][1] ?? '');
    }

    /**
     * The next minor version after every saved revision of the same major:
     * 1.0 -> 1.1, 1.9 -> 1.10.
     */
    private function nextVersion(string $from): string
    {
        $major = explode('.', $from)[0];
        $highest = CreditScorecardRevision::query()
            ->where('version', 'like', "{$major}.%")
            ->pluck('version')
            ->map(fn (string $version) => (int) (explode('.', $version)[1] ?? 0))
            ->max() ?? 0;

        return $major.'.'.($highest + 1);
    }
}
