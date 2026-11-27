<?php

namespace App\Http\Requests\LoanOrigination;

use App\Http\Requests\Concerns\DateInputMessages;
use App\Models\Branch;
use App\Services\BranchAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The rules a link save shares (create and update). A link's branch must be
 * one of the caller's own branches; a link for every branch (none) needs
 * `branches:view_all`. Managing links needs `loan_origination:settings`.
 */
trait SavesOriginationLink
{
    public const BRANCH_REFUSAL = 'Choose one of your branches. A link for every branch needs access to all branches.';

    public function authorize(): bool
    {
        return OriginationAccess::canManageLinks($this->user());
    }

    /** @throws AuthorizationException */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(OriginationAccess::LINK_REFUSAL);
    }

    /**
     * @param  bool  $creating  whether every required field must be sent
     * @return array<string, mixed>
     */
    protected function linkRules(bool $creating): array
    {
        $present = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$present, 'string', 'max:100'],
            'active' => ['sometimes', 'boolean'],
            'is_test' => ['sometimes', 'boolean'],
            'branch_id' => [$creating ? 'present' : 'sometimes', 'nullable', 'integer'],
            'loan_product_ids' => ['sometimes', 'array', 'max:100'],
            'loan_product_ids.*' => ['integer', 'distinct', Rule::exists('loan_products', 'id')],
            'expires_at' => ['sometimes', 'nullable', 'date', 'date_format:'.DateInputMessages::DATE_TIME_FORMATS, 'after:now'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]+$/'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:150'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->has('branch_id') || $validator->errors()->has('branch_id')) {
                return;
            }

            $branchId = $this->input('branch_id');
            $user = $this->user();
            $viewAll = $user->can(BranchAccess::VIEW_ALL);

            if ($branchId === null) {
                if (! $viewAll) {
                    $validator->errors()->add('branch_id', self::BRANCH_REFUSAL);
                }

                return;
            }

            if (! app(BranchAccess::class)->canSee($user, (int) $branchId)) {
                $validator->errors()->add('branch_id', self::BRANCH_REFUSAL);

                return;
            }

            if (! Branch::query()->whereKey((int) $branchId)->where('is_active', true)->exists()) {
                $validator->errors()->add('branch_id', 'Choose an active branch.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...DateInputMessages::dateTime(['expires_at' => 'expiry date']),
            'name.required' => 'Give the link a name.',
            'name.max' => 'A link name can be at most 100 characters.',
            'branch_id.integer' => self::BRANCH_REFUSAL,
            'loan_product_ids.*.exists' => 'Choose loan products that exist.',
            'loan_product_ids.*.integer' => 'Choose loan products that exist.',
            'loan_product_ids.*.distinct' => 'Each loan product can be listed once.',
            'expires_at.after' => 'The expiry must be in the future.',
            'contact_phone.regex' => 'Enter a contact number using digits, spaces, dashes or a leading +.',
            'contact_email.email' => 'Enter a valid contact email.',
        ];
    }
}
