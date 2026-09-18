<?php

namespace App\Http\Requests\Accounting;

/**
 * Editing a DRAFT entry. A posted one is refused by the controller with a 422
 * long before these rules matter — see JournalPoster::updateDraft().
 *
 * Identical rules to create, deliberately: an edit produces a whole entry, not
 * a patch. Journal lines only mean anything as a set (they have to balance
 * together), so accepting a partial set would let an edit leave a draft whose
 * lines are half from this request and half from the last one.
 */
class UpdateJournalRequest extends StoreJournalRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('journals:create');
    }
}
