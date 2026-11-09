<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Traits\ScopesBranchFilter;
use App\Http\Controllers\Controller;
use App\Services\Accounting\BookBuilder;
use App\Services\Accounting\ColumnBookBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * The BIR books of account.
 *
 * All four: the general journal, the general ledger, and the cash receipts and
 * cash disbursements books. The cash books read the journals the automatic
 * posting engine now writes for releases, collections and expenses, and tell a
 * receipt from a disbursement by which way the entry moved money — see
 * BookBuilder::movedMoney().
 *
 * Every book answers `{data: AccountingBook}` — the same shape, deliberately,
 * because `book-report.tsx` renders all of them through ONE call site keyed by
 * `BookKind`. A book that returned its own shape would not merely need new
 * frontend code, it would need a branch in a component whose whole point is
 * that BIR prescribes the same columns for every book.
 *
 * The two cash books also come in columns (`/columns`, `{data: ColumnBook}`,
 * see ColumnBookBuilder): the same journals, one row each, laid out by
 * account. They are a second view of those two books, not a fifth book, so the
 * four line books keep their one shape.
 */
class AccountingBookController extends Controller
{
    use ScopesBranchFilter;

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

    public function __construct(
        private BookBuilder $books,
        private ColumnBookBuilder $columnBooks,
    ) {}

    #[OA\Get(
        path: '/api/accounting/books/general-journal',
        summary: 'General journal — the book of original entry',
        description: "Every posting in the period in chronological order. Answers `{data: AccountingBook}`. Reads `status IN ('posted','reversed')`: a reversed entry is a posted historical fact whose mirror nets it to zero, while a draft is not in the books at all. Returns EVERY line in the range — there is no row cap, because the response has no way to say it was truncated; the range itself is capped at 366 days instead.",
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'One of the caller\'s branches (another answers 403). Without it, every branch the caller holds; journals with no branch only for branches:view_all.', schema: new OA\Schema(type: 'integer')),
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
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'One of the caller\'s branches (another answers 403). Without it, every branch the caller holds; journals with no branch only for branches:view_all.', schema: new OA\Schema(type: 'integer')),
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

    #[OA\Get(
        path: '/api/accounting/books/cash-receipts',
        summary: 'Cash receipts book — every entry that brought money in',
        description: 'The general journal narrowed to the entries whose money-account lines net to a debit, listed whole (every line, so the book balances) in chronological order. Answers `{data: AccountingBook}`. A money account is one with a `cash_kind`, as on the Cash & Bank screen. A transfer between two money accounts nets to zero and appears in neither cash book. Same status filter, 366-day cap and no row cap as the general journal.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'One of the caller\'s branches (another answers 403). Without it, every branch the caller holds; journals with no branch only for branches:view_all.', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The cash receipts book'),
            new OA\Response(response: 403, description: 'Missing accounting:view'),
            new OA\Response(response: 422, description: 'Missing dates, reversed range, or a range longer than 366 days'),
        ],
    )]
    public function cashReceipts(): JsonResponse
    {
        return $this->book('cash_receipts');
    }

    #[OA\Get(
        path: '/api/accounting/books/cash-disbursements',
        summary: 'Cash disbursements book — every entry that paid money out',
        description: "The general journal narrowed to the entries whose money-account lines net to a credit, listed whole in chronological order. Answers `{data: AccountingBook}`. A reversal is its own movement, so the reversal of a collection appears here on the day it was reversed. Receipts less disbursements over a period equals the cash flow statement's `net_change` for it.",
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'One of the caller\'s branches (another answers 403). Without it, every branch the caller holds; journals with no branch only for branches:view_all.', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The cash disbursements book'),
            new OA\Response(response: 403, description: 'Missing accounting:view'),
            new OA\Response(response: 422, description: 'Missing dates, reversed range, or a range longer than 366 days'),
        ],
    )]
    public function cashDisbursements(): JsonResponse
    {
        return $this->book('cash_disbursements');
    }

    #[OA\Get(
        path: '/api/accounting/books/cash-receipts/columns',
        summary: 'Cash receipts book in columns — one row per journal',
        description: 'The same journals as the cash receipts book, one row each, in date then journal-number order. Answers `{data: ColumnBook}` in integer centavos. `money_columns` are the money accounts that moved, in code order, each row reading debit − credit. `columns`, in this order and only where the role is mapped: loans_receivable, interest_income, penalty_income, insurance_premium_payable, share_capital, borrower_advances, gcash_service_income, each the credits on the mapped account. Every other line is in `sundry` with its own side. Each row and the totals balance: money = columns + sundry credit − sundry debit. Not paged: the book is printed whole, within the same 366-day limit.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'One of the caller\'s branches (another answers 403). Without it, every branch the caller holds; journals with no branch only for branches:view_all.', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The cash receipts book in columns'),
            new OA\Response(response: 403, description: 'Missing accounting:view, or a branch outside the caller\'s'),
            new OA\Response(response: 422, description: 'Missing dates, reversed range, or a range longer than 366 days'),
        ],
    )]
    public function cashReceiptsColumns(): JsonResponse
    {
        return $this->columnBook('cash_receipts');
    }

    #[OA\Get(
        path: '/api/accounting/books/cash-disbursements/columns',
        summary: 'Cash disbursements book in columns — one row per journal',
        description: 'The same journals as the cash disbursements book, one row each, in date then journal-number order. Answers `{data: ColumnBook}` in integer centavos. `money_columns` are the money accounts that moved, in code order, each row reading credit − debit. `columns`, in this order and only where the role is mapped: loans_receivable, accounts_payable, share_capital, borrower_advances, each the debits on the mapped account. Every other line, such as a release\'s withheld deductions, is in `sundry` with its own side. Each row and the totals balance: money = columns + sundry debit − sundry credit. Not paged: the book is printed whole, within the same 366-day limit.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'One of the caller\'s branches (another answers 403). Without it, every branch the caller holds; journals with no branch only for branches:view_all.', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The cash disbursements book in columns'),
            new OA\Response(response: 403, description: 'Missing accounting:view, or a branch outside the caller\'s'),
            new OA\Response(response: 422, description: 'Missing dates, reversed range, or a range longer than 366 days'),
        ],
    )]
    public function cashDisbursementsColumns(): JsonResponse
    {
        return $this->columnBook('cash_disbursements');
    }

    /**
     * Every book, which differ only in which entries they read and in order.
     */
    private function book(string $kind): JsonResponse
    {
        $this->authorize('accounting:view');

        [$from, $to, $branchScope] = $this->period();

        return response()->json([
            'data' => $this->books->build($kind, $from, $to, $branchScope),
        ]);
    }

    /**
     * A cash book in columns, over the same period rules as the line books.
     *
     * @param  'cash_receipts'|'cash_disbursements'  $kind
     */
    private function columnBook(string $kind): JsonResponse
    {
        $this->authorize('accounting:view');

        [$from, $to, $branchScope] = $this->period();

        return response()->json([
            'data' => $this->columnBooks->build($kind, $from, $to, $branchScope),
        ]);
    }

    /**
     * The validated period and branch scope every book is read over.
     *
     * `from` and `to` are REQUIRED rather than defaulted to the current month.
     * A book of account is a book FOR A PERIOD, and a defaulted range would
     * mean the figures on screen answered a question nobody asked — the same
     * reason the screen sends both on every request.
     *
     * @return array{0: string, 1: string, 2: int|list<int>|null}
     */
    private function period(): array
    {
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

        return [
            $from->toDateString(),
            $to->toDateString(),
            $this->branchScope($filters['branch_id'] ?? null),
        ];
    }
}
