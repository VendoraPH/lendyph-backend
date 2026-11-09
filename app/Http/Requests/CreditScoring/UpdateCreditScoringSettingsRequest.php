<?php

namespace App\Http\Requests\CreditScoring;

use App\Models\CreditScoringSetting;
use App\Services\CreditScoring\Api\SettingsEditor;
use App\Services\CreditScoring\Decimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * `Partial<CreditScoringSettings>` from the Settings screen.
 *
 * - `score_model_version` is read only: a body carrying it answers 422.
 * - The definition lists are the server's: accepted and ignored.
 * - Rates above 0 and at most 100, with at most four decimals, never
 *   rounded; the manageable level can't be above the caution level.
 */
class UpdateCreditScoringSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('credit_scoring:settings');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rate = ['sometimes', 'required', 'numeric', 'gt:0', 'max:100', 'decimal:0,4'];

        return [
            'score_model_version' => ['missing'],
            'privacy_notice' => ['sometimes', 'required', 'string', 'max:10000'],
            'dsr_manageable_max' => $rate,
            'dsr_caution_max' => $rate,
            'capacity_share' => $rate,
            'max_active_loans' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'hard_past_due_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:365'],
            'decline_alert_points' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'confidence_definitions' => ['sometimes', 'nullable', 'array'],
            'hard_flag_definitions' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['score_model_version.missing' => 'This field is read-only.'];
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

            $settings = CreditScoringSetting::current();
            $manageable = $this->has('dsr_manageable_max') ? Decimal::scaled($this->input('dsr_manageable_max'), 4) : SettingsEditor::rate($settings, 'dsr_manageable_max');
            $caution = $this->has('dsr_caution_max') ? Decimal::scaled($this->input('dsr_caution_max'), 4) : SettingsEditor::rate($settings, 'dsr_caution_max');

            if ($manageable > $caution) {
                $validator->errors()->add('dsr_manageable_max', 'The manageable debt service level can\'t be above the caution level.');
            }
        }];
    }

    /**
     * The fields to save; the definition lists are never saved.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        return collect($this->validated())->except(['confidence_definitions', 'hard_flag_definitions'])->all();
    }
}
