<?php

namespace App\Http\Requests\Branch;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An interbranch transfer step (Phase 6 MB-BE5). Each subclass names the one
 * permission it needs; the route binding has already answered 404 for a
 * transfer whose two branches the caller can't see. Which branch the caller
 * must see for the step itself (the source to approve, the destination to
 * receive) is InterbranchTransfers' check, in words.
 */
class InterbranchTransferRequest extends FormRequest
{
    public const PERMISSION = 'interbranch_transfers:view';

    public const REFUSAL = 'Viewing interbranch transfers needs the interbranch transfers view permission.';

    public function authorize(): bool
    {
        return $this->user()->can(static::PERMISSION);
    }

    /** @throws AuthorizationException */
    protected function failedAuthorization(): void
    {
        throw new AuthorizationException(static::REFUSAL);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
