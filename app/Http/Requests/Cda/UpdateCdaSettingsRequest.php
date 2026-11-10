<?php

namespace App\Http\Requests\Cda;

use App\Models\CdaOfficer;
use App\Services\Cda\CdaAccess;
use Closure;

/**
 * PUT /cda/settings, partial: deadline days, reminder days (distinct, 1 to
 * 120), the trainings each officer position needs (position → training
 * keys), and the asset size bounds (micro < small < medium, in pesos).
 */
class UpdateCdaSettingsRequest extends CdaFormRequest
{
    protected function permission(): ?string
    {
        return CdaAccess::SETTINGS;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'annual_deadline_days' => ['sometimes', 'integer', 'min:1', 'max:366'],
            'mediation_deadline_days' => ['sometimes', 'integer', 'min:1', 'max:366'],
            'reminder_days' => ['sometimes', 'array', 'max:10'],
            'reminder_days.*' => ['integer', 'min:1', 'max:120', 'distinct'],
            'required_trainings' => ['sometimes', 'array', function (string $attribute, mixed $value, Closure $fail): void {
                if (array_is_list($value) && $value !== []) {
                    $fail('Required trainings are listed per officer position.');
                }

                foreach (array_keys($value) as $position) {
                    if (! in_array($position, CdaOfficer::POSITIONS, true)) {
                        $fail("\"{$position}\" isn't an officer position.");
                    }
                }
            }],
            'required_trainings.*' => ['array', 'list', 'max:20'],
            'required_trainings.*.*' => ['string', 'regex:/^[a-z][a-z0-9_]{0,79}$/', 'distinct'],
            'asset_class_thresholds' => ['sometimes', 'array:micro,small,medium', 'required_array_keys:micro,small,medium', function (string $attribute, mixed $value, Closure $fail): void {
                $micro = (float) ($value['micro'] ?? 0);
                $small = (float) ($value['small'] ?? 0);
                $medium = (float) ($value['medium'] ?? 0);

                if (! ($micro < $small && $small < $medium)) {
                    $fail('The asset size bounds must rise: micro below small, small below medium.');
                }
            }],
            'asset_class_thresholds.*' => ['numeric', 'decimal:0,2', 'min:0.01', 'max:9999999999999.99'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reminder_days.*.distinct' => 'Each reminder day is listed once.',
            'required_trainings.*.array' => 'List the training keys for each position.',
            'required_trainings.*.list' => 'List the training keys for each position.',
        ];
    }
}
