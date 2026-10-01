<?php

namespace App\Http\Requests\GCash;

use App\Models\GCashNonMember;
use Illuminate\Validation\Rules\Unique;

/**
 * The frontend's edit dialog posts the whole record back, so the rules are the
 * store rules rather than `sometimes` — a partial update would silently blank
 * the fields the dialog did not send.
 */
class UpdateGCashNonMemberRequest extends StoreGCashNonMemberRequest
{
    /** The walk-in being edited may keep its own ID. */
    protected function uniqueIdRule(): Unique
    {
        /** @var GCashNonMember $nonMember */
        $nonMember = $this->route('nonMember');

        return parent::uniqueIdRule()->ignore($nonMember->id);
    }
}
