<?php

namespace App\Http\Requests\CreditScoring;

use App\Models\Borrower;
use App\Services\CreditScoring\Api\Portfolio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Filters for the scored member list (`BorrowerScoreFilters`). A
 * `branch_id` outside the caller's branches answers 403 in the controller.
 * `search` matches the member's name or code, case-insensitive, with %, _
 * and \ matched literally (Borrower::scopeSearch(), without contact
 * details).
 * - `trend`: up, down or flat, the row's `score_trend`.
 * - `member_type`: regular or associate, the member's own type.
 * - `loan_product_id`: members with a collectible loan (released, ongoing,
 *   defaulted) of that product that the caller can see.
 * `has_active_loan`, `past_due` and `loan_product_id` are loan facts and
 * need `loans:view` (422 otherwise), as the row fields do.
 */
class ListBorrowerScoresRequest extends FormRequest
{
    public const BOOLEANS = ['0', '1', 'true', 'false'];

    public const TRENDS = ['up', 'down', 'flat'];

    public function authorize(): bool
    {
        return $this->user()->can('credit_scoring:view');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'risk_level' => ['sometimes', 'string', Rule::in(Portfolio::RISK_LEVELS)],
            'min_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'max_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'has_active_loan' => ['sometimes', Rule::in(self::BOOLEANS)],
            'past_due' => ['sometimes', Rule::in(self::BOOLEANS)],
            'trend' => ['sometimes', 'string', Rule::in(self::TRENDS)],
            'member_type' => ['sometimes', 'string', Rule::in(Borrower::MEMBER_TYPES)],
            'loan_product_id' => ['sometimes', 'integer', 'min:1', Rule::exists('loan_products', 'id')],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            foreach (['has_active_loan', 'past_due'] as $field) {
                if ($this->has($field) && ! $this->user()->can('loans:view')) {
                    $validator->errors()->add($field, 'Filtering by past due or active loans needs permission to view loans.');
                }
            }

            if ($this->has('loan_product_id') && ! $this->user()->can('loans:view')) {
                $validator->errors()->add('loan_product_id', 'Filtering by loan product needs permission to view loans.');
            }

            if ($this->filled('min_score') && $this->filled('max_score') && (int) $this->input('max_score') < (int) $this->input('min_score')) {
                $validator->errors()->add('max_score', 'The highest score can\'t be below the lowest.');
            }
        }];
    }

    /**
     * A boolean filter, or null when it isn't sent.
     */
    public function flag(string $key): ?bool
    {
        return $this->has($key) ? filter_var($this->input($key), FILTER_VALIDATE_BOOLEAN) : null;
    }
}
