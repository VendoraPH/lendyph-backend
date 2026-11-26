<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
            // Ignored since "Remember me" was removed (finish run round 7);
            // still accepted so older clients keep signing in.
            'remember' => ['sometimes', 'boolean'],
        ];
    }
}
