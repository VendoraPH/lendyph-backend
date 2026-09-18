<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Services\Accounting\PostingRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The automatic posting engine refused to write an entry, so the lending
 * transaction that asked for one has been rolled back.
 *
 * ## Why this exists rather than `ValidationException::withMessages()`
 *
 * The rest of the accounting module raises validation exceptions, and the
 * response shape here is deliberately identical to one — same 422, same
 * `{message, errors}` body — so the frontend needs no new branch and every
 * message still lands on the `accounting` key it already renders.
 *
 * What differs is the dependency. `ValidationException::withMessages()` resolves
 * the validator out of the container, which means every rule that can refuse
 * input can only run inside a booted application. {@see PostingRules}
 * is pure arithmetic over a literal mapping and has no other reason to need a
 * framework: making it throw this instead is what lets the rule set be tested as
 * a rule set, with no database and no application boot, the same way
 * `posting-rules.test.ts` tests the frontend half.
 *
 * ## Why 422 and not 500
 *
 * Nearly everything that raises this is fixable by a person, at a screen:
 * a posting role nobody has mapped yet, a payment method the module has no
 * settlement account for, a loan whose deductions do not add up to its
 * principal. A 500 would report those as the server being broken and would
 * bury the one sentence that says which setting to go and change.
 *
 * ## What it means when a caller sees this
 *
 * NOTHING WAS SAVED. The posting happens inside the same transaction as the
 * release or the collection that caused it, so a throw here takes the whole
 * business transaction with it. That is the trade the module is built on: an
 * operation that refuses loudly is recoverable, and books that quietly miss an
 * entry are not.
 */
class CannotPostToTheBooksException extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }

    /**
     * Rendered as a validation failure so the frontend's existing error
     * handling reads it without a special case.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['accounting' => [$this->getMessage()]],
        ], 422);
    }
}
