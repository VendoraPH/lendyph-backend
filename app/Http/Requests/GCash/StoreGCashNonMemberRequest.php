<?php

namespace App\Http\Requests\GCash;

use App\Models\GCashNonMember;
use App\Rules\UniqueWalkInIdNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The ID document is optional (Kevin's request, approved by Arvin on
 * 2026-11-02), but both or neither: a type without a number names no document.
 * Both keys are sent together or not at all (`present_with`), so an update that
 * sends one of them can't leave the row half-filled.
 * A walk-in saved without an ID is checked against the others by name and
 * mobile number instead, as a soft warning the teller can acknowledge.
 */
class StoreGCashNonMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('gcash:transact');
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'mobile_number' => ['required', 'string', 'max:32'],
            'id_type' => ['nullable', 'required_with:id_number', 'present_with:id_number', 'string', 'max:64'],
            'id_number' => ['nullable', 'required_with:id_type', 'present_with:id_type', 'string', 'max:64', $this->uniqueIdRule()],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'acknowledge_possible_duplicate' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'id_type.required_with' => 'Enter the ID type too, or leave both ID fields blank.',
            'id_number.required_with' => 'Enter the ID number too, or leave both ID fields blank.',
            'id_type.present_with' => 'Send both ID fields, or neither.',
            'id_number.present_with' => 'Send both ID fields, or neither.',
        ];
    }

    /**
     * Name and mobile warning, for a walk-in saved without an ID. A 422 on
     * `possible_duplicate` rather than a block: the same name and handset can
     * be two real people, so `acknowledge_possible_duplicate: true` saves
     * anyway. Skipped while any other field fails, so the warning never hides
     * a real error.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()
                    || $this->hasIdAfterSave()
                    || $this->boolean('acknowledge_possible_duplicate')) {
                    return;
                }

                $match = GCashNonMember::findPossibleDuplicate(
                    (string) $this->input('full_name'),
                    (string) $this->input('mobile_number'),
                    $this->ignoredWalkInId(),
                    $this->onlyMatchesWithId(),
                );

                if ($match !== null) {
                    $validator->errors()->add(
                        'possible_duplicate',
                        "A walk-in named {$match->full_name} with the same mobile number is already registered. Check that this is a different customer, or save anyway.",
                    );
                }
            },
        ];
    }

    /**
     * The fields the walk-in row stores; the acknowledgement is not one of them.
     *
     * @return array<string, mixed>
     */
    public function walkInAttributes(): array
    {
        return $this->safe()->except('acknowledge_possible_duplicate');
    }

    /** Whether the saved walk-in will carry an ID; on store, only what was sent counts. */
    protected function hasIdAfterSave(): bool
    {
        return $this->filled('id_number');
    }

    /** A new walk-in without an ID is checked against every walk-in; an update narrows this. */
    protected function onlyMatchesWithId(): bool
    {
        return false;
    }

    /** The walk-in being edited, which never counts as its own duplicate; none on store. */
    protected function ignoredWalkInId(): ?int
    {
        return null;
    }

    /** One live walk-in per ID document; see UniqueWalkInIdNumber. */
    protected function uniqueIdRule(): UniqueWalkInIdNumber
    {
        return new UniqueWalkInIdNumber($this->idType(), $this->ignoredWalkInId());
    }

    /** A non-string type already fails `string`; it must not 500 the rule first. */
    protected function idType(): ?string
    {
        $idType = $this->input('id_type');

        return is_string($idType) ? $idType : null;
    }
}
