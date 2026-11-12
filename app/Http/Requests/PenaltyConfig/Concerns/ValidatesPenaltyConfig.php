<?php

namespace App\Http\Requests\PenaltyConfig\Concerns;

use App\Models\PenaltyConfig;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * The body of POST and PUT /penalty-configs, answered 422 in words:
 *
 * - `name`: required, at most 100 characters, unique.
 * - `applicable_product_ids`: existing loan products; an active
 *   configuration needs at least one.
 * - `conditions`: 1 to 20 brackets. Taken in `days_from` order, the first
 *   starts at day 1 and each later one the day after the previous one ends
 *   (no gaps, no overlaps). `days_to` is at least `days_from`, and only the
 *   last may be null (open-ended). `rate` is 0 to 100 with at most 4
 *   decimals, never rounded.
 * - `status`: active or inactive; a new configuration is active.
 *
 * Which products are already in another active configuration is checked by
 * PenaltyConfigService under a lock, not here.
 */
trait ValidatesPenaltyConfig
{
    public const MAX_BRACKETS = 20;

    public const MAX_DAYS = 36500;

    /** @return array<string, mixed> */
    protected function penaltyConfigRules(?PenaltyConfig $existing): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('penalty_configs', 'name')->ignore($existing?->id)],
            'status' => ['sometimes', 'string', Rule::in(PenaltyConfig::STATUSES)],
            'applicable_product_ids' => ['present', 'array'],
            'applicable_product_ids.*' => ['integer', 'distinct', 'exists:loan_products,id'],
            'conditions' => ['required', 'array', 'min:1', 'max:'.self::MAX_BRACKETS],
            'conditions.*' => ['array:days_from,days_to,rate'],
            'conditions.*.days_from' => ['required', 'integer', 'min:1', 'max:'.self::MAX_DAYS],
            'conditions.*.days_to' => ['present', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_DAYS],
            'conditions.*.rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
        ];
    }

    /** @return array<string, string> */
    protected function penaltyConfigMessages(): array
    {
        return [
            'name.required' => 'Enter a name for the configuration.',
            'name.max' => 'The name can be at most 100 characters.',
            'name.unique' => 'Another configuration already has this name.',
            'status.in' => 'The status must be active or inactive.',
            'applicable_product_ids.present' => 'Choose the loan products this configuration covers.',
            'applicable_product_ids.array' => 'Choose the loan products this configuration covers.',
            'applicable_product_ids.*.integer' => 'Choose loan products from the list.',
            'applicable_product_ids.*.distinct' => 'A loan product is listed twice.',
            'applicable_product_ids.*.exists' => 'One of the chosen loan products no longer exists.',
            'conditions.required' => 'Add at least one bracket.',
            'conditions.array' => 'Add at least one bracket.',
            'conditions.min' => 'Add at least one bracket.',
            'conditions.max' => 'A configuration can have at most '.self::MAX_BRACKETS.' brackets.',
            'conditions.*.array' => 'Each bracket needs days from, days to and a rate.',
            'conditions.*.days_from.required' => 'Enter the day each bracket starts on.',
            'conditions.*.days_from.integer' => 'Days must be whole numbers.',
            'conditions.*.days_from.min' => 'Days start at day 1, the day after the due date.',
            'conditions.*.days_from.max' => 'Days can be at most '.number_format(self::MAX_DAYS).'.',
            'conditions.*.days_to.present' => 'Enter the day each bracket ends on, or leave the last one open-ended.',
            'conditions.*.days_to.integer' => 'Days must be whole numbers.',
            'conditions.*.days_to.min' => 'Days start at day 1, the day after the due date.',
            'conditions.*.days_to.max' => 'Days can be at most '.number_format(self::MAX_DAYS).'.',
            'conditions.*.rate.required' => 'Enter a rate for each bracket.',
            'conditions.*.rate.numeric' => 'The rate must be a number.',
            'conditions.*.rate.min' => 'The rate can\'t be below 0%.',
            'conditions.*.rate.max' => 'The rate can be at most 100%.',
            'conditions.*.rate.decimal' => 'The rate can have at most 4 decimal places.',
        ];
    }

    /**
     * The checks across brackets and fields, once each field is valid on its
     * own.
     */
    protected function guardPenaltyConfig(Validator $validator, ?PenaltyConfig $existing): void
    {
        $validator->after(function (Validator $v) use ($existing): void {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $this->guardBracketRanges($v);

            $status = $this->input('status', $existing?->status ?? PenaltyConfig::STATUS_ACTIVE);

            if ($status === PenaltyConfig::STATUS_ACTIVE && (array) $this->input('applicable_product_ids') === []) {
                $v->errors()->add('applicable_product_ids', 'An active configuration needs at least one product.');
            }
        });
    }

    private function guardBracketRanges(Validator $v): void
    {
        $rows = [];

        foreach ((array) $this->input('conditions') as $index => $row) {
            $rows[] = [
                'index' => $index,
                'from' => (int) $row['days_from'],
                'to' => $row['days_to'] === null ? null : (int) $row['days_to'],
            ];
        }

        usort($rows, fn (array $a, array $b): int => $a['from'] <=> $b['from']);

        $last = array_key_last($rows);
        $previous = null;

        foreach ($rows as $position => $row) {
            $key = "conditions.{$row['index']}";
            $number = $position + 1;

            if ($row['to'] !== null && $row['to'] < $row['from']) {
                $v->errors()->add("{$key}.days_to", "Bracket {$number} ends before it starts: days to must be at least days from.");
            }

            if ($row['to'] === null && $position !== $last) {
                $v->errors()->add("{$key}.days_to", "Only the last bracket can be open-ended. Bracket {$number} needs the day it ends on.");
            }

            if ($previous === null) {
                if ($row['from'] !== 1) {
                    $v->errors()->add("{$key}.days_from", 'The first bracket must start at day 1, the day after the due date.');
                }
            } elseif ($previous['to'] !== null && $row['from'] !== $previous['to'] + 1) {
                $expected = $previous['to'] + 1;
                $problem = $row['from'] > $expected
                    ? "leaves a gap after day {$previous['to']}"
                    : "overlaps bracket {$position}";

                $v->errors()->add("{$key}.days_from", "Bracket {$number} {$problem}: it must start on day {$expected}, the day after bracket {$position} ends.");
            }

            $previous = $row;
        }
    }
}
