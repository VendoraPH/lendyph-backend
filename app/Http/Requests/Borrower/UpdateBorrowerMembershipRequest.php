<?php

namespace App\Http\Requests\Borrower;

use App\Models\Borrower;
use App\Services\Cda\MembershipDetails;
use App\Services\Portal\Auth\MemberNumber;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /borrowers/{borrower}/membership (finish run round 2, decision 15):
 * the member's membership date, member type and membership number, each
 * optional, at least one sent. Needs `borrowers:update`; a member outside the
 * caller's branches answers 404 through the route binding.
 *
 * - The membership date is `Y-m-d`, not after today (Manila). It can be
 *   changed but not cleared.
 * - The member type is regular or associate.
 * - The membership number follows the members import (CustomerRowNormalizer):
 *   kept in capitals without spaces, the form members sign in with
 *   (MemberNumber); plain keyboard characters; at most 30; never starting like
 *   a member code (BRW), which the app gives out itself and which shares the
 *   portal sign-in; a digit, and not only zeros, so a spreadsheet placeholder
 *   isn't taken for one; and no other member's, refused naming the number
 *   only. Blank sets it back to the member code. The number the member already
 *   has, and their own code, are always theirs to keep.
 *
 * Who has membership details, and a former member's end date, are checked
 * under the member's row lock (MembershipDetails).
 */
class UpdateBorrowerMembershipRequest extends FormRequest
{
    private const NUMBER_MAX = 30;

    public function authorize(): bool
    {
        return $this->user()->can('borrowers:update');
    }

    /** The number is validated, compared and stored in the form members sign in with. */
    protected function prepareForValidation(): void
    {
        $number = $this->input('membership_number');

        if (is_string($number)) {
            $this->merge(['membership_number' => MemberNumber::normalise($number)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $member = $this->route('borrower');
        $memberId = $member instanceof Borrower ? $member->id : null;

        return [
            'membership_date' => ['sometimes', 'bail', 'required', 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString()],
            'member_type' => ['sometimes', 'bail', 'required', 'string', Rule::in(Borrower::MEMBER_TYPES)],
            'membership_number' => [
                'sometimes',
                'bail',
                'nullable',
                'string',
                fn (string $attribute, mixed $value, Closure $fail) => $this->checkNumber($member, $value, $fail),
                Rule::unique('borrowers', 'membership_number')->ignore($memberId),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (MembershipDetails::FIELDS as $field) {
                    if ($this->has($field)) {
                        return;
                    }
                }

                $validator->errors()->add('membership', 'Send the membership date, member type or membership number to change.');
            },
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $type = 'Choose Regular or Associate for the member type.';

        return [
            'membership_date.required' => 'Enter the membership date. It can be changed but not cleared.',
            'membership_date.date_format' => 'The membership date must be a date written as year-month-day, such as 2026-01-15.',
            'membership_date.before_or_equal' => 'The membership date can\'t be in the future.',
            'member_type.required' => $type,
            'member_type.string' => $type,
            'member_type.in' => $type,
            'membership_number.string' => 'Enter the membership number as text, or leave it blank to use the member code.',
            'membership_number.unique' => MembershipDetails::numberTaken(':input'),
        ];
    }

    /**
     * The import's rules for a number the member doesn't have yet, in the
     * import's order. Their own number, as stored, and their own code pass.
     */
    private function checkNumber(mixed $member, mixed $number, Closure $fail): void
    {
        if (! is_string($number) || $number === '') {
            return;
        }

        if ($member instanceof Borrower
            && ($number === MemberNumber::normalise((string) $member->membership_number) || $number === $member->borrower_code)) {
            return;
        }

        $refusal = match (true) {
            preg_match('/^[\x21-\x7E]+$/', $number) !== 1 => 'The membership number can hold only plain letters, numbers and punctuation.',
            strlen($number) > self::NUMBER_MAX => 'The membership number can be at most '.self::NUMBER_MAX.' characters.',
            str_starts_with($number, Borrower::CODE_PREFIX) => 'The membership number can\'t start with '.Borrower::CODE_PREFIX.': member codes start that way, and the app gives them out itself. Leave it blank to use the member code.',
            preg_match('/\d/', $number) !== 1 => 'The membership number needs at least one digit, so a placeholder such as N/A isn\'t taken for a number.',
            // Its digits all zero and no letters: `0`, `000`, `00-00`.
            preg_match('/^[^A-Z1-9]+$/', $number) === 1 => 'The membership number can\'t be only zeros, so a placeholder such as 0 isn\'t taken for a number.',
            default => null,
        };

        if ($refusal !== null) {
            $fail($refusal);
        }
    }
}
