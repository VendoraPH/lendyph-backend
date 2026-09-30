<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorisation and filters for `GET /api/staff`, the account-officer picker.
 *
 * This is deliberately NOT user management. `GET /api/users` stays behind
 * `users:view`, which only admin and super_admin hold; this endpoint exists so
 * whoever can create or edit a loan can choose its account officer without
 * being able to read the users list. That is why it:
 *
 *  - gates on `loans:create` OR `loans:update` rather than a `users:*`
 *    permission — `AssignAccountOfficerRequest` needs `loans:update` and the
 *    new-loan form needs `loans:create`, and a role may hold either alone
 *    (the seeded `loan_processor` holds only `loans:update`);
 *  - answers a refusal with an ordinary 403. Existence masking belongs to the
 *    `users.*` routes, whose `{user}` binding is an oracle; a list that binds
 *    nothing has nothing to conceal.
 *
 * `$this->authorize()` in the controller takes one ability, hence a
 * FormRequest for the `canAny()`.
 */
class ListStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canAny(['loans:create', 'loans:update']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The requested page size, clamped to 1..100 like every other list.
     */
    public function perPage(): int
    {
        return min(max((int) ($this->validated('per_page') ?? 15), 1), 100);
    }
}
