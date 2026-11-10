<?php

namespace App\Services\Savings\Api;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * How the accounts and teller services say no: a 422 on a field, in words,
 * or a 409 when another request got there first. Both roll back the write's
 * transaction (and its idempotency key) before anything is answered.
 */
final class Refuse
{
    /** @throws ValidationException */
    public static function field(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }

    /** @throws HttpResponseException */
    public static function conflict(string $message): never
    {
        throw new HttpResponseException(response()->json(['success' => false, 'message' => $message], 409));
    }

    /** "₱1,234.50", for messages. */
    public static function peso(int $centavos): string
    {
        return ($centavos < 0 ? '-' : '').'₱'.number_format(abs($centavos) / 100, 2);
    }
}
