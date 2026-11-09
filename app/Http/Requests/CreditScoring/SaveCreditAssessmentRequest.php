<?php

namespace App\Http\Requests\CreditScoring;

use App\Models\CreditApplicationProfile;
use App\Services\CreditScoring\Api\AssessmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A loan application's optional credit details and the member's notice
 * acknowledgement. Every field is optional: a field left out is unchanged,
 * a field sent as null is cleared. Money has at most two decimals and is at
 * most ₱9,999,999,999.99; the employment status is one of
 * AssessmentService::EMPLOYMENT_STATUSES. Saving needs `loans:view` as well
 * as `loans:update`, since the answer shows the application's details.
 */
class SaveCreditAssessmentRequest extends FormRequest
{
    public const MONEY_FIELDS = ['other_income', 'business_income', 'household_expenses', 'outside_debt_payments'];

    public const PROFILE_FIELDS = CreditApplicationProfile::DETAIL_FIELDS;

    public function authorize(): bool
    {
        return $this->user()->can('credit_scoring:view') && $this->user()->can('loans:view') && $this->user()->can('loans:update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $money = ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'];
        $count = ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'];

        return [
            'other_income' => $money,
            'business_income' => $money,
            'household_expenses' => $money,
            'outside_debt_payments' => $money,
            'outside_lenders_count' => $count,
            'employment_status' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(AssessmentService::EMPLOYMENT_STATUSES))],
            'business_start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'years_at_residence' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999.99', 'decimal:0,2'],
            'verified_references_count' => $count,
            'income_verification' => ['sometimes', 'nullable', Rule::in(CreditApplicationProfile::INCOME_VERIFICATIONS)],
            'notice_acknowledged' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function profileFields(): array
    {
        return collect($this->validated())->only(self::PROFILE_FIELDS)->all();
    }

    public function noticeAcknowledged(): ?bool
    {
        return $this->has('notice_acknowledged') ? $this->boolean('notice_acknowledged') : null;
    }
}
