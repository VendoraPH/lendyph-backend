<?php

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('settings:update');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:20', 'unique:branches'],
            'address' => ['nullable', 'string', 'max:500'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            ...self::profileRules(),
        ];
    }

    /**
     * The profile fields added in Phase 6 (MB-BE1), for create and edit. The
     * manager is an active user. The CDA authority fields are "to verify".
     * Status, main office and closing date are never taken from here.
     *
     * @return array<string, mixed>
     */
    public static function profileRules(): array
    {
        return [
            'email' => ['nullable', 'email', 'max:255'],
            'manager_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('status', 'active')],
            'opened_on' => ['nullable', 'date_format:Y-m-d'],
            'cda_authority_number' => ['nullable', 'string', 'max:100'],
            'cda_authority_issued_on' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
