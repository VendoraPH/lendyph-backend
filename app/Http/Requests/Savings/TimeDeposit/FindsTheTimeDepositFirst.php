<?php

namespace App\Http\Requests\Savings\TimeDeposit;

use App\Services\Savings\TimeDeposits\TimeDepositScope;

/**
 * The order a time deposit's own routes answer in, as the accounts' do
 * (AuthorizesSavingsRecord): the permission first (403), then the
 * `{timeDeposit}` in the caller's branches (404, the same as a missing one),
 * and only then the body's validation (422). Without it, another branch's
 * placement answered 422 to an incomplete body, which told the caller it
 * exists (Phase 6 MB-BE2, the extended route sweep).
 */
trait FindsTheTimeDepositFirst
{
    protected function mayActOnTheTimeDeposit(string $permission): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can($permission)) {
            return false;
        }

        app(TimeDepositScope::class)->find($user, (int) $this->route('timeDeposit'));

        return true;
    }
}
