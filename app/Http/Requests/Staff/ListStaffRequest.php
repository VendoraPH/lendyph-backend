<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorisation and filters for `GET /api/staff`, the account-officer picker.
 *
 * This is deliberately NOT user management. `GET /api/users` stays behind
 * `users:view`, which only admin and super_admin hold; this endpoint exists so
 * whoever can create, edit or restructure a loan can choose its account
 * officer without being able to read the users list. That is why it:
 *
 *  - gates on `loans:create`, `loans:update` OR `loans:restructure` rather
 *    than a `users:*` permission — one per form with an officer picker: the
 *    new-loan form needs `loans:create`, `AssignAccountOfficerRequest` needs
 *    `loans:update` and `RestructureLoanRequest` needs `loans:restructure`,
 *    and a role may hold any one of them alone (the seeded `loan_processor`
 *    holds only `loans:update`, and the roles screen can build one holding
 *    only `loans:restructure`);
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
        return $this->user()->canAny(['loans:create', 'loans:update', 'loans:restructure']);
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
