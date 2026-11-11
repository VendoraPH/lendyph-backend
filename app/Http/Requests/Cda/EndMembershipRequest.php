<?php

namespace App\Http\Requests\Cda;

use App\Models\Borrower;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /borrowers/{borrower}/end-membership (CDA design §1): withdrawn or
 * deceased, on a date no later than today (Manila), with a reason. Needs
 * `borrowers:update`.
 */
class EndMembershipRequest extends FormRequest
{
    use CdaAttributeWords;

    public function authorize(): bool
    {
        return $this->user()->can('borrowers:update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(Borrower::FORMER_MEMBER_STATUSES)],
            'ended_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now()->toDateString()],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.in' => 'A membership ends as withdrawn or deceased.',
            'ended_on.before_or_equal' => 'The end date can\'t be in the future.',
            'reason.required' => 'Give the reason the membership ended.',
        ];
    }
}
