<?php

namespace App\Http\Requests\ShareCapital;

use App\Http\Requests\Concerns\ReferenceNumberMessages;
use App\Services\ShareCapitalService;
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
     * `cycle` is the half of the month the run is for, `15` or `30` (finish
     * run round 8), required.
     */
    public function rules(): array
    {
        return [
            'cycle' => self::cycleRules(required: true),
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
     * The run's half, `15` or `30`: a string, so a list holding one is
     * refused. Shared with GET /auto-credit/status's `?cycle=`.
     *
     * @return list<mixed>
     */
    public static function cycleRules(bool $required): array
    {
        return [$required ? 'required' : 'sometimes', 'string', Rule::in(ShareCapitalService::CYCLES)];
    }

    /**
     * The cycle's refusals, in words.
     *
     * @return array<string, string>
     */
    public static function cycleMessages(): array
    {
        return [
            'cycle.required' => 'Choose the half of the month this run is for: the 15th or the 30th.',
            'cycle.string' => 'A run is for the 15th or the 30th.',
            'cycle.in' => 'A run is for the 15th or the 30th.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('method')) {
            $this->merge(['method' => 'bank_transfer']);
        }

        // A number (15 or 30) reads as its half.
        if (is_int($this->input('cycle'))) {
            $this->merge(['cycle' => (string) $this->input('cycle')]);
        }
    }
}
