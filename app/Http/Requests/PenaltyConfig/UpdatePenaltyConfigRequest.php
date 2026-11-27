<?php

namespace App\Http\Requests\PenaltyConfig;

use App\Http\Requests\PenaltyConfig\Concerns\ValidatesPenaltyConfig;
use App\Models\PenaltyConfig;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /penalty-configs/{id}: the whole configuration, as on create. `status`
 * left out keeps the stored one.
 */
class UpdatePenaltyConfigRequest extends FormRequest
{
    use ValidatesPenaltyConfig;

    public function authorize(): bool
    {
        return $this->user()->can('penalties:update');
    }

    public function rules(): array
    {
        return $this->penaltyConfigRules($this->config());
    }

    public function messages(): array
    {
        return $this->penaltyConfigMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $this->guardPenaltyConfig($validator, $this->config());
    }

    private function config(): PenaltyConfig
    {
        return $this->route('penaltyConfig');
    }
}
