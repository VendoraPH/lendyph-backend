<?php

namespace App\Http\Requests\Savings;

use App\Services\BranchAccess;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Savings settings, tax rules, products and rates are company-wide: a change
 * applies to every branch. Like Phase 2's company-wide reminder settings,
 * writing them needs `savings:settings` and `branches:view_all`. Reading
 * them stays on `savings:view`.
 */
trait AuthorizesCompanyWideSavings
{
    public const COMPANY_WIDE_REFUSAL = 'Savings settings, tax rules, products and rates apply to every branch, so changing them needs the savings settings permission and access to all branches.';

    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('savings:settings') && $user->can(BranchAccess::VIEW_ALL);
    }

    /** @throws AuthorizationException */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(self::COMPANY_WIDE_REFUSAL);
    }
}
