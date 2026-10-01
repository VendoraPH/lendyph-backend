<?php

namespace App\Http\Requests\GCash;

use App\Rules\UniqueWalkInIdNumber;
use Illuminate\Foundation\Http\FormRequest;

class StoreGCashNonMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('gcash:transact');
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'mobile_number' => ['required', 'string', 'max:32'],
            'id_type' => ['required', 'string', 'max:64'],
            'id_number' => ['required', 'string', 'max:64', $this->uniqueIdRule()],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** One live walk-in per ID document; see UniqueWalkInIdNumber. */
    protected function uniqueIdRule(): UniqueWalkInIdNumber
    {
        return new UniqueWalkInIdNumber($this->idType());
    }

    /** A non-string type already fails `string`; it must not 500 the rule first. */
    protected function idType(): ?string
    {
        $idType = $this->input('id_type');

        return is_string($idType) ? $idType : null;
    }
}
