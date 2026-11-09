<?php

namespace App\Services\CreditScoring\Api;

use App\Models\CreditScorecardRevision;
use App\Models\CreditScoringSetting;
use App\Models\User;
use App\Services\CreditScoring\Decimal;
use App\Services\CreditScoring\FactorTable;
use App\Services\CreditScoring\Scorer;
use Illuminate\Support\Facades\DB;

/**
 * The Scorecard Configuration screen's reads and saves.
 *
 * The screen may change the category weights and the risk bands' minimum
 * scores. Labels, descriptions, factor rules and policy rules are the
 * revision's own and are only checked to be unchanged. A save that changes
 * something writes a new immutable revision (the next minor version,
 * unvalidated) and puts it in use; both writes audit themselves. Nothing is
 * recalculated here: new scores use the revision from the next run, and
 * every old score keeps its revision stamp.
 */
final class ScorecardEditor
{
    public const TOTAL_HUNDREDTHS = 10_000;

    /**
     * The `ScorecardConfig` shape, plus `risk_bands`, `version` and
     * `validated`.
     *
     * @return array<string, mixed>
     */
    public function config(?CreditScorecardRevision $revision = null): array
    {
        $revision ??= $this->current();

        return [
            'version' => $revision->version,
            'validated' => (bool) $revision->validated,
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
     * The revision's factors as the screen lists them.
     *
     * @return list<array{id: int, category_key: string, label: string, points: int, is_active: bool}>
     */
    public static function factorRules(CreditScorecardRevision $revision): array
    {
        return array_map(fn (array $f) => [
            'id' => (int) $f['id'],
            'category_key' => (string) $f['category_key'],
            'label' => (string) $f['label'],
            'points' => (int) $f['points'],
            'is_active' => (bool) ($f['is_active'] ?? true),
        ], array_values($revision->factors ?? []));
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
     * (key => min_score). Returns the revision in use afterwards.
     *
     * @param  array<string, int|float|string>  $weights
     * @param  array<string, int>|null  $bandMinimums
     */
    public function save(User $user, array $weights, ?array $bandMinimums): CreditScorecardRevision
    {
        return DB::transaction(function () use ($user, $weights, $bandMinimums) {
            $settings = CreditScoringSetting::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $current = $settings->currentRevision()->firstOrFail();

            $categories = array_map(function (array $category) use ($weights) {
                $hundredths = FactorTable::hundredths($weights[$category['key']] ?? $category['weight_percent']);

                return array_merge($category, ['weight_percent' => Decimal::unscaled($hundredths, 2)]);
            }, array_values($current->categories));

            $bands = array_map(fn (array $band) => array_merge($band, [
                'min_score' => (int) ($bandMinimums[$band['key']] ?? $band['min_score']),
            ]), array_values($current->risk_bands));

            if (! $this->differs($current, $categories, $bands)) {
                return $current;
            }

            $revision = CreditScorecardRevision::query()->create([
                'version' => $this->nextVersion($current->version),
                'engine_version' => Scorer::ENGINE_VERSION,
                'categories' => $categories,
                'factors' => $current->factors,
                'risk_bands' => $bands,
                'policy_rules' => $current->policy_rules,
                'validated' => false,
                'notes' => "Saved from Scorecard Configuration, from revision {$current->version}. Unvalidated: not yet tested against the cooperative's own loan outcomes.",
                'created_by' => $user->id,
            ]);

            $settings->update(['current_revision_id' => $revision->id, 'updated_by' => $user->id]);

            return $revision;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $categories
     * @param  list<array<string, mixed>>  $bands
     */
    private function differs(CreditScorecardRevision $current, array $categories, array $bands): bool
    {
        $weights = fn (array $list) => array_map(fn (array $c) => FactorTable::hundredths($c['weight_percent']), array_column($list, null, 'key'));
        $minimums = fn (array $list) => array_map(fn (array $b) => (int) $b['min_score'], array_column($list, null, 'key'));

        return $weights($categories) !== $weights(array_values($current->categories))
            || $minimums($bands) !== $minimums(array_values($current->risk_bands));
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
