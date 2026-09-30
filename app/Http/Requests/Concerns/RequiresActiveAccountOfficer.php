<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * The `account_officer_id` constraint shared by every write that sets a loan's
 * account officer: create, restructure, edit and the dedicated reassign.
 *
 * Only an active user can carry a loan — a deactivated account cannot sign in,
 * so it would leave the loan with nobody following it up. The reassign endpoint
 * enforced that while create and restructure accepted any user id, so a
 * restructure could silently carry a deactivated officer over from its source
 * loan. One rule, one message, on all four.
 */
trait RequiresActiveAccountOfficer
{
    protected function activeAccountOfficerRule(): Exists
    {
        return Rule::exists('users', 'id')->where('status', 'active');
    }

    protected function activeAccountOfficerMessage(): string
    {
        return 'Choose an active user as the account officer.';
    }
}
