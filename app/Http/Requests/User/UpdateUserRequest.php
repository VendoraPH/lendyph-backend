<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('users.update');
    }

    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'username' => ['sometimes', 'string', 'alpha_dash', 'max:50', Rule::unique('users')->ignore($userId)],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($userId)],
            'mobile_number' => ['nullable', 'string', 'max:20'],
            'branch_id' => ['sometimes', 'exists:branches,id'],
            'role' => ['sometimes', 'string', 'exists:roles,name'],
        ];
    }
}
