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
 * `?for=savings` (gap round S4) also lets a caller holding `savings:create`
 * read it, for the account officer on the savings opening form
 * (OpenSavingsAccountRequest needs `savings:create`). The rows are the same
 * names-only list of active staff in the caller's branches; without the
 * parameter the loan rule above is unchanged.
 *
 * `?for=origination` (Phase 6, LO-B3) is the online applications' assign
 * picker: the caller needs `loan_origination:update`, and the rows are only
 * the staff who could be assigned, the active holders of
 * `loan_origination:update` in the caller's branches (StaffController).
 * Holding a loan permission doesn't open it, since its rows differ.
 *
 * `$this->authorize()` in the controller takes one ability, hence a
 * FormRequest for the `canAny()`.
 */
class ListStaffRequest extends FormRequest
{
    /** The one form outside lending with an officer picker: opening a savings account. */
    public const FOR_SAVINGS = 'savings';

    /** The online applications' assign picker (LO-B3). */
    public const FOR_ORIGINATION = 'origination';

    public function authorize(): bool
    {
        $user = $this->user();

        if ($this->query('for') === self::FOR_ORIGINATION) {
            return $user->can('loan_origination:update');
        }

        return $user->canAny(['loans:create', 'loans:update', 'loans:restructure'])
            || ($this->query('for') === self::FOR_SAVINGS && $user->can('savings:create'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'for' => ['nullable', 'string', 'in:'.self::FOR_SAVINGS.','.self::FOR_ORIGINATION],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'for.string' => 'The staff list can be asked for the savings opening form (for=savings) or the online applications\' assign picker (for=origination), or without either.',
            'for.in' => 'The staff list can be asked for the savings opening form (for=savings) or the online applications\' assign picker (for=origination), or without either.',
        ];
    }

    public function forOrigination(): bool
    {
        return $this->validated('for') === self::FOR_ORIGINATION;
    }

    /**
     * The requested page size, clamped to 1..100 like every other list.
     */
    public function perPage(): int
    {
        return min(max((int) ($this->validated('per_page') ?? 15), 1), 100);
    }
}
