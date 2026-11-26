<?php

namespace App\Http\Requests\ShareCapital;

use App\Http\Requests\Concerns\ReferenceNumberMessages;
use App\Services\ShareCapitalService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProcessAutoCreditRequest extends FormRequest
{
    use ReferenceNumberMessages;

    public function authorize(): bool
    {
        return $this->user()->can('auto_credit:process');
    }

    /**
     * The settlement account the run's credits came through, stored on each
     * row and posted to: `bank_transfer` unless picked, as auto-pay settles
     * through the bank. `reference_number` is optional, whatever the method.
     * `cycle_date` is the cycle the run is for (finish run round 8),
     * required: a month's 15th or its 30th, the current one or a missed one.
     */
    public function rules(): array
    {
        return [
            'cycle_date' => self::cycleRules(required: true),
            'method' => ['sometimes', Rule::in(ShareCapitalService::AUTO_CREDIT_METHODS)],
            'reference_number' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...$this->referenceNumberMessages(), ...self::cycleMessages()];
    }

    /**
     * The run's cycle, YYYY-MM-DD: a month's 15th or its 30th (the month's
     * last day), the current one by today in Manila or an earlier one up to
     * the lookback, never a future one (ShareCapitalService::cycleRefusal()).
     * Shared with GET /auto-credit/status's `?cycle_date=`.
     *
     * @return list<mixed>
     */
    public static function cycleRules(bool $required): array
    {
        return [
            'bail', $required ? 'required' : 'sometimes', 'string', 'date_format:Y-m-d',
            function (string $attribute, mixed $value, Closure $fail): void {
                $refusal = ShareCapitalService::cycleRefusal((string) $value, CarbonImmutable::now(ShareCapitalService::TIMEZONE));

                if ($refusal !== null) {
                    $fail($refusal);
                }
            },
        ];
    }

    /**
     * The cycle's refusals, in words.
     *
     * @return array<string, string>
     */
    public static function cycleMessages(): array
    {
        return [
            'cycle_date.required' => 'Choose the cycle this run is for: a 15th or a 30th.',
            'cycle_date.string' => 'The cycle date must be written as YYYY-MM-DD.',
            'cycle_date.date_format' => 'The cycle date must be written as YYYY-MM-DD.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('method')) {
            $this->merge(['method' => 'bank_transfer']);
        }
    }
}
