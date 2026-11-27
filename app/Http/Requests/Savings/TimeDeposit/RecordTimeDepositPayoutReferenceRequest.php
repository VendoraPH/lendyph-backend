<?php

namespace App\Http\Requests\Savings\TimeDeposit;

use App\Models\TimeDeposit;
use App\Services\Savings\PayoutReferences;
use App\Services\Savings\TimeDeposits\TimeDepositScope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /savings/time-deposits/{timeDeposit}/payout-reference` (finish run
 * round 6): the reference of a maturity or pre-termination payout sent before
 * it existed.
 *
 * In the API's order: 403 without either payout permission, 404 for a
 * placement outside the caller's branches, 403 when the caller's permissions
 * don't post that payout (settling at maturity needs savings:transact,
 * pre-termination savings:close), then the body (422). A placement not paid
 * out yet passes here and is refused in words by PayoutReferences.
 */
class RecordTimeDepositPayoutReferenceRequest extends FormRequest
{
    private ?TimeDeposit $deposit = null;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! ($user->can('savings:transact') || $user->can('savings:close'))) {
            return false;
        }

        $this->deposit = app(TimeDepositScope::class)->find($user, (int) $this->route('timeDeposit'));
        $permission = PayoutReferences::permissionFor($this->deposit);

        return $permission === null || $user->can($permission);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reference' => ['required', 'string', 'max:'.PayoutReferences::TIME_DEPOSIT_MAX]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reference.required' => 'Enter the reference of this payout.',
            'reference.string' => 'The reference must be text.',
            'reference.max' => 'The reference can be at most '.PayoutReferences::TIME_DEPOSIT_MAX.' characters.',
        ];
    }

    public function deposit(): TimeDeposit
    {
        return $this->deposit ?? app(TimeDepositScope::class)->find($this->user(), (int) $this->route('timeDeposit'));
    }
}
