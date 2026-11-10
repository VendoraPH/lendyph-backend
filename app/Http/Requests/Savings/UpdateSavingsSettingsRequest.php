<?php

namespace App\Http\Requests\Savings;

use App\Models\SavingsSetting;
use App\Services\Savings\SavingsMath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * `PUT /savings/settings` (savings:settings). Every field is optional; the
 * second tier must stay above the first, comparing what is sent with what is
 * stored. Dual control above the second tier needs at least two checkers.
 */
class UpdateSavingsSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('savings:settings');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tier1_limit' => ['sometimes', 'required', ...SavingsRules::money()],
            'tier2_limit' => ['sometimes', 'required', ...SavingsRules::money()],
            'tier3_checkers' => ['sometimes', 'required', 'integer', 'between:2,5'],
            'dormancy_months' => ['sometimes', 'required', 'integer', 'between:1,120'],
            'large_transaction_alert' => ['sometimes', 'required', ...SavingsRules::money(), 'gt:0'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['tier1_limit', 'tier2_limit'])) {
                return;
            }

            $stored = SavingsSetting::current();
            $tier1 = SavingsMath::centavos((string) $this->input('tier1_limit', $stored->tier1_limit));
            $tier2 = SavingsMath::centavos((string) $this->input('tier2_limit', $stored->tier2_limit));

            if ($tier2 <= $tier1) {
                $validator->errors()->add('tier2_limit', 'The second approval limit must be above the first ('.SavingsMath::pesos($tier1).').');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...SavingsRules::messages([
                'tier1_limit' => 'first approval limit',
                'tier2_limit' => 'second approval limit',
                'large_transaction_alert' => 'large transaction alert',
            ]),
            'large_transaction_alert.gt' => 'The large transaction alert must be above ₱0.00.',
            'tier3_checkers.integer' => 'The number of checkers above the second limit must be a whole number.',
            'tier3_checkers.between' => 'Above the second approval limit, between 2 and 5 different checkers must approve.',
            'dormancy_months.integer' => 'The months before an account turns dormant must be a whole number.',
            'dormancy_months.between' => 'An account can turn dormant after 1 to 120 months without member activity.',
            '*.required' => 'This setting can\'t be left empty.',
        ];
    }
}
