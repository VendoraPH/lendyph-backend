<?php

namespace App\Http\Requests\Savings\AutoCredit;

use App\Services\Savings\AutoCredit\AutoCreditRunner;
use App\Services\Savings\AutoCredit\Cycle;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `GET /savings/auto-credit/preview` and `POST /savings/auto-credit/process`
 * (savings:post): the cycle (a 15th or a month's last day, today or up to
 * Cycle::LOOKBACK_DAYS days ago), the settlement method (`bank_transfer`
 * unless picked) and an optional reference number.
 */
class AutoCreditRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('savings:post');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cycle_date' => [
                'bail', 'required', 'date_format:Y-m-d',
                'before_or_equal:'.now()->toDateString(),
                'after_or_equal:'.now()->subDays(Cycle::LOOKBACK_DAYS)->toDateString(),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (Cycle::slotOf((string) $value) === null) {
                        $fail('The cycle date must be a 15th or the last day of a month (the "30th").');
                    }
                },
            ],
            'method' => ['sometimes', 'string', Rule::in(AutoCreditRunner::METHODS)],
            'reference_number' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cycle_date.required' => 'Enter the cycle date.',
            'cycle_date.date_format' => 'The cycle date must be written as YYYY-MM-DD.',
            'cycle_date.before_or_equal' => 'The cycle date can\'t be after today.',
            'cycle_date.after_or_equal' => 'The cycle date can be at most '.Cycle::LOOKBACK_DAYS.' days ago.',
            'method.string' => 'Choose how the money came in: bank transfer, cash, GCash or Maya.',
            'method.in' => 'Choose how the money came in: bank transfer, cash, GCash or Maya.',
            'reference_number.string' => 'The reference number must be text.',
            'reference_number.max' => 'The reference number can be at most 100 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('method')) {
            $this->merge(['method' => AutoCreditRunner::DEFAULT_METHOD]);
        }
    }
}
