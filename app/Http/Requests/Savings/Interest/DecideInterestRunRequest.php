<?php

namespace App\Http\Requests\Savings\Interest;

use App\Services\Savings\Interest\RunPresenter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /savings/interest-runs/{run}/reject` and `/reverse`
 * (savings:approve): `{reason}`, which the run keeps and the audit trail
 * shows.
 */
class DecideInterestRunRequest extends FormRequest
{
    /**
     * The permission first (403), then the run in the caller's branches (404,
     * the same as a missing one), and only then the reason (422), the order
     * the other savings records answer in (Phase 6 MB-BE2 review).
     */
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null || ! $user->can('savings:approve')) {
            return false;
        }

        app(RunPresenter::class)->find($user, (int) $this->route('run'));

        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Give a reason.',
            'reason.string' => 'Give the reason in words.',
            'reason.max' => 'The reason can be at most 500 characters.',
        ];
    }
}
