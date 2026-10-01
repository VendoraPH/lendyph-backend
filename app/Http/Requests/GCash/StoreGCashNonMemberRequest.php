<?php

namespace App\Http\Requests\GCash;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

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

    public function messages(): array
    {
        return [
            'id_number.unique' => 'A walk-in with this ID type and ID number is already registered. Search for them instead of adding them again.',
        ];
    }

    /**
     * One live walk-in per ID document. A validation rule, not a unique index:
     * a removed walk-in is soft-deleted and keeps its ID, and it must not stop
     * the same person being registered again. The comparison follows the
     * column collation, so it ignores case.
     */
    protected function uniqueIdRule(): Unique
    {
        return Rule::unique('gcash_non_members', 'id_number')
            ->where('id_type', $this->input('id_type'))
            ->withoutTrashed();
    }
}
