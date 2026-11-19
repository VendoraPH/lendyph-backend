<?php

namespace App\Http\Requests\LoanOrigination;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PATCH /loan-origination/links/{link}`: any of the link's own fields. The
 * token and counters never change. The route binding already answers 404
 * for another branch's link.
 */
class UpdateOriginationLinkRequest extends FormRequest
{
    use SavesOriginationLink;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->linkRules(creating: false);
    }
}
