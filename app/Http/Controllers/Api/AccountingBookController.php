<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\BookBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * The BIR books of account.
 *
 * Two books so far — the general journal and the general ledger. The cash
 * receipts and cash disbursements books are the other half of the set and are
 * not built here; they need the automatic posting engine to be able to tell a
 * receipt from a disbursement by source, and until that lands they would be
 * empty for a reason a reader could not see.
 *
 * Both books answer `{data: AccountingBook}` — the same shape, deliberately,
 * because `book-report.tsx` renders all of them through ONE call site keyed by
 * `BookKind`. A book that returned its own shape would not merely need new
 * frontend code, it would need a branch in a component whose whole point is
 * that BIR prescribes the same columns for every book.
 */
class AccountingBookController extends Controller
{
    /**
     * The longest period a book may be asked for, in days.
     *
     * A year, because that is what a book of account IS — BIR books are kept
     * and registered per fiscal year, so this is the domain's own bound rather
     * than a guess at how many rows are too many. 366 for leap years, so
     * "1 January to 31 December" is never off by one on the wrong year.
     *
     * This is the limit INSTEAD of a row cap, and that is the point.
     * `AccountingBook` has no `truncated` flag and the screen renders
     * `book.rows` whole, so a row cap would hand back a partial book that
     * totals only the rows it kept and looks complete — the failure this
     * codebase has already shipped six times. A span limit is refused loudly,
     * names the number, and is fixed by the caller narrowing dates they can
     * see. Within the limit, every posting is returned.
     */
    private const MAX_RANGE_DAYS = 366;

    public function __construct(private BookBuilder $books) {}

    #[OA\Get(
        path: '/api/accounting/books/general-journal',
        summary: 'General journal — the book of original entry',
        description: "Every posting in the period in chronological order. Answers `{data: AccountingBook}`. Reads `status IN ('posted','reversed')`: a reversed entry is a posted historical fact whose mirror nets it to zero, while a draft is not in the books at all. Returns EVERY line in the range — there is no row cap, because the response has no way to say it was truncated; the range itself is capped at 366 days instead.",
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The general journal'),
            new OA\Response(response: 403, description: 'Missing accounting:view'),
            new OA\Response(response: 422, description: 'Missing dates, reversed range, or a range longer than 366 days'),
        ],
    )]
    public function generalJournal(): JsonResponse
    {
        return $this->book('general_journal');
    }

    #[OA\Get(
        path: '/api/accounting/books/general-ledger',
        summary: 'General ledger — the book of final entry',
        description: 'The same postings as the general journal over the same range, regrouped under the account each one touched and ordered by account code (which is statement order). Answers `{data: AccountingBook}`. Because it is one query with a different ORDER BY, its `total_debit` and `total_credit` are necessarily identical to the general journal\'s for the same period.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The general ledger book'),
            new OA\Response(response: 403, description: 'Missing accounting:view'),
            new OA\Response(response: 422, description: 'Missing dates, reversed range, or a range longer than 366 days'),
        ],
    )]
    public function generalLedger(): JsonResponse
    {
        return $this->book('general_ledger');
    }

    /**
     * Both books, which differ only in how they are ordered.
     *
     * `from` and `to` are REQUIRED rather than defaulted to the current month.
     * A book of account is a book FOR A PERIOD, and a defaulted range would
     * mean the figures on screen answered a question nobody asked — the same
     * reason the screen sends both on every request.
     */
    private function book(string $kind): JsonResponse
    {
        $this->authorize('accounting:view');

        $filters = request()->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $from = CarbonImmutable::parse($filters['from'])->startOfDay();
        $to = CarbonImmutable::parse($filters['to'])->startOfDay();

        if ($to->lt($from)) {
            throw ValidationException::withMessages([
                'to' => ['The end of the period cannot be before its start.'],
            ]);
        }

        // diffInDays is exclusive of the start, so 1 Jan to 31 Dec is 364.
        if ($from->diffInDays($to) + 1 > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages([
                'from' => [
                    'A book of account covers at most one year at a time ('
                    .self::MAX_RANGE_DAYS.' days). Narrow the period and try again.',
                ],
            ]);
        }

        return response()->json([
            'data' => $this->books->build(
                $kind,
                $from->toDateString(),
                $to->toDateString(),
                $filters['branch_id'] ?? null,
            ),
        ]);
    }
}
