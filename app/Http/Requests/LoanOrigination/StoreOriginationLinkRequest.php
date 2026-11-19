<?php

namespace App\Http\Requests\LoanOrigination;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /loan-origination/links`. The token and counters are the server's:
 * anything sent for them is ignored. No products means every product
 * offered online.
 */
class StoreOriginationLinkRequest extends FormRequest
{
    use SavesOriginationLink;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->linkRules(creating: true);
    }
}
