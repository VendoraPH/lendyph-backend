<?php

namespace App\Http\Requests\CreditScoring;

use App\Models\CreditScorecardRevision;
use App\Services\CreditScoring\Api\Portfolio;
use App\Services\CreditScoring\Api\ScorecardEditor;
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
 * - `factor_rules` and `policy_rules`: optional; when sent they must equal
 *   the current revision's (they round-trip; the screen can't change them).
 * - `risk_bands`: optional; the six keys once each, minimum scores falling
 *   from very low to very high risk, the lowest 0.
 */
class UpdateScorecardConfigRequest extends FormRequest
{
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

            foreach (['factor_rules' => ScorecardEditor::factorRules($current), 'policy_rules' => ScorecardEditor::policyRules($current)] as $field => $stored) {
                if ($this->has($field) && ! $this->sameRules($this->input($field), $stored)) {
                    $validator->errors()->add($field, "The {$this->label($field)} can't be changed here; send them unchanged or leave them out.");
                }
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

    private function label(string $field): string
    {
        return $field === 'factor_rules' ? 'factor rules' : 'policy rules';
    }
}
