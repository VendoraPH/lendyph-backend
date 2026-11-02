<?php

namespace App\Http\Requests\GCash;

use App\Models\GCashNonMember;

/**
 * The frontend's edit dialog posts the whole record back, so the rules are the
 * store rules rather than `sometimes` — a partial update would silently blank
 * the fields the dialog did not send. The ID keys are the exception: both may
 * be left out, and then the saved ID is kept.
 */
class UpdateGCashNonMemberRequest extends StoreGCashNonMemberRequest
{
    /** Left-out ID keys keep the saved ID, so the saved row decides. */
    protected function hasIdAfterSave(): bool
    {
        return $this->has('id_number')
            ? $this->filled('id_number')
            : $this->nonMember()->id_number !== null;
    }

    /**
     * Only when the name or mobile number really changed (as the check compares
     * them), or the ID was just removed. A pair the teller already saved anyway
     * must not warn again on an unrelated edit such as the remarks. Asked only
     * when the saved walk-in will have no ID, so a saved ID here means this
     * edit removes it.
     */
    protected function shouldCheckNameAndMobile(): bool
    {
        $nonMember = $this->nonMember();

        return GCashNonMember::comparableName((string) $this->input('full_name')) !== GCashNonMember::comparableName($nonMember->full_name)
            || GCashNonMember::comparableMobile((string) $this->input('mobile_number')) !== GCashNonMember::comparableMobile($nonMember->mobile_number)
            || $nonMember->id_number !== null;
    }

    /**
     * The walk-in being edited may keep its own ID, and its own name and
     * mobile number never warn as a possible duplicate of itself.
     */
    protected function ignoredWalkInId(): ?int
    {
        return $this->nonMember()->id;
    }

    private function nonMember(): GCashNonMember
    {
        /** @var GCashNonMember $nonMember */
        $nonMember = $this->route('nonMember');

        return $nonMember;
    }
}
