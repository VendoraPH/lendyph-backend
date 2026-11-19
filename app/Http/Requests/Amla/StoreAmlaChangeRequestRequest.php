<?php

namespace App\Http\Requests\Amla;

use App\Models\AmlaCalendarEntry;
use App\Models\AmlaChangeRequest;
use App\Services\Amla\Cdd\RiskRules;
use App\Services\Amla\Detection\DetectionRules;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Foundation\AmlaSettings;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /amla/change-requests: ask for a change to the AMLA settings or
 * calendar, with a reason. Needs `amla:settings` and head-office access.
 *
 * - settings: `values` (any of AmlaSettings::DEFINITIONS, partial),
 *   `verified` (key → what confirmed it) and `to_verify` (keys to mark
 *   unconfirmed again). The review cycles keep high ≤ medium ≤ low.
 * - calendar_add: `date`, `kind`, `name`, `source`.
 * - calendar_remove: `entry_id`.
 * - calendar_confirm_year: `year`.
 * - risk_rules: `definition`, the whole next rules version (RiskRules).
 */
class StoreAmlaChangeRequestRequest extends AmlaFormRequest
{
    protected function permissions(): array
    {
        return [AmlaAccess::SETTINGS];
    }

    protected function headOffice(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'type' => ['required', 'string', Rule::in(AmlaChangeRequest::TYPES)],
            'reason' => ['required', 'string', 'max:1000'],
        ];

        return match ($this->input('type')) {
            AmlaChangeRequest::SETTINGS => [...$rules, ...$this->settingsRules()],
            AmlaChangeRequest::CALENDAR_ADD => [...$rules,
                'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
                'kind' => ['required', 'string', Rule::in(array_keys(AmlaCalendarEntry::KINDS))],
                'name' => ['required', 'string', 'max:150'],
                'source' => ['required', 'string', 'max:255'],
            ],
            AmlaChangeRequest::CALENDAR_REMOVE => [...$rules,
                'entry_id' => ['required', 'integer', Rule::exists('amla_calendar_entries', 'id')],
            ],
            AmlaChangeRequest::CALENDAR_CONFIRM_YEAR => [...$rules,
                'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            ],
            AmlaChangeRequest::RISK_RULES, AmlaChangeRequest::DETECTION_RULES => [...$rules,
                'definition' => ['required', 'array'],
            ],
            default => $rules,
        };
    }

    /** @return array<string, mixed> */
    private function settingsRules(): array
    {
        $keys = array_keys(AmlaSettings::DEFINITIONS);
        $knownKeys = function (string $attribute, mixed $value, Closure $fail) use ($keys): void {
            if (! is_array($value)) {
                return;
            }

            foreach (array_keys($value) as $key) {
                if (! in_array($key, $keys, true)) {
                    $fail("\"{$key}\" isn't an AMLA setting.");
                }
            }
        };

        $rules = [
            'values' => ['sometimes', 'array', $knownKeys],
            'values.ctr_threshold' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999.99'],
            'values.ctr_cash_channels' => ['sometimes', 'array', 'list', 'min:1'],
            'values.ctr_cash_channels.*' => ['string', 'distinct', Rule::in(AmlaSettings::CHANNELS)],
            'verified' => ['sometimes', 'array', $knownKeys],
            'verified.*' => ['required', 'string', 'max:255'],
            'to_verify' => ['sometimes', 'array', 'list'],
            'to_verify.*' => ['string', 'distinct', Rule::in($keys)],
        ];

        foreach (AmlaSettings::INTEGER_LIMITS as $key => [$min, $max]) {
            $rules["values.{$key}"] = ['sometimes', 'integer', "min:{$min}", "max:{$max}"];
        }

        return $rules;
    }

    /** @return list<Closure> */
    public function after(): array
    {
        if ($this->input('type') === AmlaChangeRequest::RISK_RULES) {
            return [function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach (RiskRules::definitionErrors((array) $this->input('definition')) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            }];
        }

        if ($this->input('type') === AmlaChangeRequest::DETECTION_RULES) {
            return [function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                foreach (app(DetectionRules::class)->definitionErrors((array) $this->input('definition')) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            }];
        }

        if ($this->input('type') !== AmlaChangeRequest::SETTINGS) {
            return [];
        }

        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $sent = (array) $this->input('values', []);
            $values = app(AmlaSettings::class)->current()->values;

            foreach ($sent as $key => $value) {
                $values[$key] = AmlaSettings::normalise($key, $value);
            }

            if (! AmlaSettings::reviewOrderHolds($values)) {
                $validator->errors()->add('values', AmlaSettings::REVIEW_ORDER_MESSAGE);
            }

            $both = array_intersect(array_keys((array) $this->input('verified', [])), (array) $this->input('to_verify', []));

            if ($both !== []) {
                $validator->errors()->add('to_verify', 'A setting is either confirmed or still to verify, not both.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'values.ctr_threshold.min' => 'The CTR threshold must be above ₱0.00.',
            'values.ctr_cash_channels.*.in' => 'Pick cash, GCash, Maya, bank transfer or online.',
            'values.ctr_cash_channels.*.distinct' => 'Each channel is listed once.',
            'verified.*.required' => 'Say what confirmed each setting.',
            'kind.in' => 'Pick a holiday, an AMLC non-reporting day or a facility suspension.',
            'date.date_format' => 'Enter a real date as YYYY-MM-DD.',
        ];
    }
}
