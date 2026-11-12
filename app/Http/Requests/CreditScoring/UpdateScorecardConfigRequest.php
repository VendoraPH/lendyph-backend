<?php

namespace App\Http\Requests\CreditScoring;

use App\Models\CreditScorecardRevision;
use App\Services\CreditScoring\Api\Portfolio;
use App\Services\CreditScoring\Api\ScorecardEditor;
use App\Services\CreditScoring\Decimal;
use App\Services\CreditScoring\FactorTable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use UnexpectedValueException;

/**
 * The whole `ScorecardConfig` from the Scorecard Configuration screen.
 *
 * - `categories`: exactly the current revision's keys, each weight between 0
 *   and 100 with at most two decimals (never rounded), totalling exactly
 *   100.00 counted in hundredths.
 * - `factor_rules`: optional; when sent, every current factor once each,
 *   with its id, category, label, own points and switch unchanged, and its
 *   rule unchanged when sent. Its ranges may change (a factor that leaves
 *   one out keeps it):
 *   - `bands`: 1 to 10, thresholds from 0 with at most two decimals,
 *     strictly falling for `at_least` and strictly rising for `at_most`
 *     (FactorTable::points() takes the first band that matches);
 *   - band points, `otherwise` and `default_points` (only on a factor that
 *     has them): whole numbers from 0 to the factor's own points.
 * - `policy_rules`: optional; when sent they must equal the current
 *   revision's (they round-trip; the screen can't change them).
 * - `risk_bands`: optional; the six keys once each, minimum scores falling
 *   from very low to very high risk, the lowest 0.
 */
class UpdateScorecardConfigRequest extends FormRequest
{
    public const MAX_BANDS = 10;

    public const MAX_THRESHOLD = 1_000_000;

    /** A factor's fields that stay as the revision has them. */
    private const FIXED_FACTOR_FIELDS = ['id', 'category_key', 'label', 'points', 'is_active', 'rule'];

    private ?CreditScorecardRevision $current = null;

    public function authorize(): bool
    {
        return $this->user()->can('credit_scoring:settings');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'categories' => ['required', 'array', 'list'],
            'categories.*' => ['required', 'array'],
            'categories.*.key' => ['required', 'string', 'max:50'],
            'categories.*.weight_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'factor_rules' => ['sometimes', 'array', 'list'],
            'factor_rules.*' => ['required', 'array'],
            'factor_rules.*.id' => ['required', 'integer'],
            'factor_rules.*.bands' => ['sometimes', 'required', 'array', 'list', 'min:1', 'max:'.self::MAX_BANDS],
            'factor_rules.*.bands.*' => ['required', 'array'],
            'factor_rules.*.bands.*.threshold' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_THRESHOLD, 'decimal:0,2'],
            'factor_rules.*.bands.*.points' => ['required', 'integer', 'min:0'],
            'factor_rules.*.otherwise' => ['sometimes', 'integer', 'min:0'],
            'factor_rules.*.default_points' => ['sometimes', 'integer', 'min:0'],
            'policy_rules' => ['sometimes', 'array', 'list'],
            'risk_bands' => ['sometimes', 'array', 'list'],
            'risk_bands.*' => ['required', 'array'],
            'risk_bands.*.key' => ['required', 'string', 'max:20'],
            'risk_bands.*.min_score' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $current = $this->currentRevision();
            $this->checkCategories($validator, $current);

            if ($this->has('factor_rules')) {
                $this->checkFactors($validator, $current);
            }

            if ($this->has('policy_rules') && ! $this->sameRules($this->input('policy_rules'), ScorecardEditor::policyRules($current))) {
                $validator->errors()->add('policy_rules', 'The policy rules can\'t be changed here; send them unchanged or leave them out.');
            }

            if ($this->has('risk_bands')) {
                $this->checkBands($validator);
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function weights(): array
    {
        return collect($this->validated('categories'))->pluck('weight_percent', 'key')->all();
    }

    /**
     * @return array<string, int>|null
     */
    public function bandMinimums(): ?array
    {
        return $this->has('risk_bands')
            ? collect($this->validated('risk_bands'))->mapWithKeys(fn (array $b) => [$b['key'] => (int) $b['min_score']])->all()
            : null;
    }

    /**
     * The sent factor ranges, by factor id: only `bands`, `otherwise` and
     * `default_points`, and only those sent; null when `factor_rules` isn't.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function factorRanges(): ?array
    {
        if (! $this->has('factor_rules')) {
            return null;
        }

        return collect($this->validated('factor_rules'))
            ->mapWithKeys(fn (array $factor) => [(int) $factor['id'] => array_intersect_key($factor, array_flip(['bands', 'otherwise', 'default_points']))])
            ->all();
    }

    private function currentRevision(): CreditScorecardRevision
    {
        return $this->current ??= app(ScorecardEditor::class)->current();
    }

    private function checkCategories(Validator $validator, CreditScorecardRevision $current): void
    {
        $sent = array_column($this->input('categories'), 'key');
        $expected = array_column($current->categories, 'key');
        $sortedSent = $sent;
        $sortedExpected = $expected;
        sort($sortedSent);
        sort($sortedExpected);

        if ($sortedSent !== $sortedExpected) {
            $validator->errors()->add('categories', 'The categories must be exactly the current scorecard\'s, once each: '.implode(', ', $expected).'.');

            return;
        }

        $total = 0;

        foreach ($this->input('categories') as $index => $category) {
            try {
                $total += FactorTable::hundredths($category['weight_percent']);
            } catch (UnexpectedValueException) {
                $validator->errors()->add("categories.{$index}.weight_percent", 'The weight must be a plain number with at most two decimals, such as 35 or 16.67.');
            }
        }

        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        if ($total !== ScorecardEditor::TOTAL_HUNDREDTHS) {
            $validator->errors()->add('categories', 'The category weights must total exactly 100.00%; they total '.number_format($total / 100, 2).'%.');
        }
    }

    private function checkBands(Validator $validator): void
    {
        $bands = collect($this->input('risk_bands'))->keyBy('key');

        if ($bands->count() !== count($this->input('risk_bands')) || $bands->keys()->sort()->values()->all() !== collect(Portfolio::RISK_LEVELS)->sort()->values()->all()) {
            $validator->errors()->add('risk_bands', 'The risk bands must be the six risk levels, once each.');

            return;
        }

        $minimums = array_map(fn (string $level) => (int) $bands[$level]['min_score'], Portfolio::RISK_LEVELS);

        for ($i = 1; $i < count($minimums); $i++) {
            if ($minimums[$i] >= $minimums[$i - 1]) {
                $validator->errors()->add('risk_bands', 'Each risk band\'s minimum score must be below the one before it, from very low to very high risk.');

                return;
            }
        }

        if (end($minimums) !== 0) {
            $validator->errors()->add('risk_bands', 'The very high risk band must start at 0.');
        }
    }

    private function checkFactors(Validator $validator, CreditScorecardRevision $current): void
    {
        $stored = collect($current->factors)->keyBy(fn (array $f) => (int) $f['id']);
        $sent = $this->input('factor_rules');
        $ids = array_map(fn (array $f) => (int) $f['id'], $sent);

        if (count($ids) !== count(array_unique($ids)) || count($ids) !== $stored->count() || array_diff($ids, $stored->keys()->all()) !== []) {
            $validator->errors()->add('factor_rules', 'The factors must be exactly the current scorecard\'s, once each.');

            return;
        }

        foreach ($sent as $index => $factor) {
            $own = $stored[(int) $factor['id']];
            $points = (int) $own['points'];
            $label = (string) $own['label'];

            foreach (self::FIXED_FACTOR_FIELDS as $field) {
                $value = $field === 'is_active' ? (bool) ($own[$field] ?? true) : $own[$field];
                // `rule` arrived with the ranges; a screen that leaves it out keeps it.
                $missing = ! array_key_exists($field, $factor) && $field !== 'rule';

                if ($missing || (array_key_exists($field, $factor) && ! self::sameValue($factor[$field], $value))) {
                    $validator->errors()->add('factor_rules', "{$label}: only its ranges can be changed here; its rule, points, category, name and switch stay as they are.");

                    continue 2;
                }
            }

            if (array_key_exists('bands', $factor)) {
                $this->checkFactorBands($validator, $index, $own, $factor['bands']);
            }

            if (array_key_exists('otherwise', $factor) && (int) $factor['otherwise'] > $points) {
                $validator->errors()->add("factor_rules.{$index}.otherwise", "{$label}: the points for any other value must be from 0 to {$points}.");
            }

            if (array_key_exists('default_points', $factor)) {
                if (! array_key_exists('default_points', $own)) {
                    $validator->errors()->add("factor_rules.{$index}.default_points", "{$label} has no points for a defaulted or written-off loan.");
                } elseif ((int) $factor['default_points'] > $points) {
                    $validator->errors()->add("factor_rules.{$index}.default_points", "{$label}: the points for a defaulted or written-off loan must be from 0 to {$points}.");
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $own
     * @param  list<array{threshold: int|float|string, points: int|string}>  $bands
     */
    private function checkFactorBands(Validator $validator, int $index, array $own, array $bands): void
    {
        $points = (int) $own['points'];
        $label = (string) $own['label'];
        $falling = $own['rule'] === 'at_least';
        $previous = null;

        foreach ($bands as $position => $band) {
            if ((int) $band['points'] > $points) {
                $validator->errors()->add("factor_rules.{$index}.bands.{$position}.points", "{$label}: each range's points must be from 0 to {$points}.");
            }

            $threshold = Decimal::scaled($band['threshold'], 2);

            if ($previous !== null && ($falling ? $threshold >= $previous : $threshold <= $previous)) {
                $validator->errors()->add("factor_rules.{$index}.bands", $falling
                    ? "{$label}: each threshold must be below the one before it, highest first; the first range the value reaches is used."
                    : "{$label}: each threshold must be above the one before it, lowest first; the first range the value doesn't exceed is used.");
            }

            $previous = $threshold;
        }
    }

    /**
     * Whether sent rules equal the stored ones, field for field, in any
     * order.
     *
     * @param  list<array<string, int|string|bool>>  $stored
     */
    private function sameRules(mixed $sent, array $stored): bool
    {
        if (! is_array($sent) || count($sent) !== count($stored)) {
            return false;
        }

        $byId = collect($stored)->keyBy('id');

        foreach ($sent as $rule) {
            $own = is_array($rule) && isset($rule['id']) ? $byId->get((int) $rule['id']) : null;

            if ($own === null) {
                return false;
            }

            foreach ($own as $field => $value) {
                if (! array_key_exists($field, $rule) || ! self::sameValue($rule[$field], $value)) {
                    return false;
                }
            }

            $byId->forget($own['id']);
        }

        return $byId->isEmpty();
    }

    private static function sameValue(mixed $sent, int|string|bool $stored): bool
    {
        return match (true) {
            is_bool($stored) => filter_var($sent, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === $stored,
            is_int($stored) => is_numeric($sent) && (string) $sent === (string) $stored,
            default => $sent === $stored,
        };
    }
}
