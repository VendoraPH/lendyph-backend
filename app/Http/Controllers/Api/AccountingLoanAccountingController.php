<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Traits\ScopesBranchFilter;
use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\Accounting\LoanAccountingSummary;
use App\Services\Accounting\LoanAgingBuilder;
use App\Services\Accounting\ReceivableAgingBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Loan Accounting: receivable aging by loan and by member, and its summary.
 *
 * Both read the LENDING tables, as GET /accounting/loans/aging does, and
 * answer in integer centavos. `accounting:view`; loans through
 * Loan::visibleTo(), and `branch_id` through ScopesBranchFilter (a branch the
 * caller can't see answers 403).
 */
class AccountingLoanAccountingController extends Controller
{
    use ScopesBranchFilter;

    public function __construct(
        private LoanAgingBuilder $loanAging,
        private LoanAccountingSummary $summary,
    ) {}

    #[OA\Get(
        path: '/api/accounting/loan-accounting/summary',
        summary: 'Loan Accounting summary: aging, the books and the loan records',
        description: 'Answers `{data: {as_of, aging, books, records, books_vs_records}}` in integer CENTAVOS. `aging` is GET /accounting/loans/aging for the same `as_of` and branch (its `count` and `count_total` are outstanding instalments, not loans). `books` is the ledger at `as_of`: `gross` (loans receivable), `allowance` (1200), `unearned_income` (1210), `adjustments_clearing` (1180, debit normal, may be negative), `adjustments_clearing_needs_review` (true when 1180 is not zero) and `net` (gross − allowance − unearned income). `records` totals the collectible loans with something unpaid (`loan_count`, `outstanding_principal`, `total_outstanding`, `past_due_amount` and `past_due_count`, past due by the grace rule). Loan records keep no history, so `records` and `books_vs_records` are always as of today and their own `as_of` says so. `books_vs_records`: `books_principal` is the books\' gross receivable today, `records_principal` is `records.outstanding_principal`, `difference` = books − records, and `is_reconciled` is true when it is 0. A loan released before the books existed shows as minus its principal.',
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'), description: 'YYYY-MM-DD. Defaults to today.'),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'One of the caller\'s branches (another answers 403). Without it, every branch the caller holds.', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The summary'),
            new OA\Response(response: 403, description: 'Missing accounting:view, or a branch outside the caller\'s'),
            new OA\Response(response: 422, description: 'A bad date or branch'),
        ],
    )]
    public function summary(): JsonResponse
    {
        $this->authorize('accounting:view');

        $filters = request()->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $this->summary->build(
                $this->asOf($filters),
                $this->branchScope($filters['branch_id'] ?? null),
                Loan::visibleTo(request()->user()),
            ),
        ]);
    }

    #[OA\Get(
        path: '/api/accounting/loan-accounting/loans',
        summary: 'Receivable aging by loan or by member',
        description: "Collectible loans with something unpaid, in integer CENTAVOS, paged on the server (25 by default, at most 100), highest `days_past_due` first, then account number. A loan row: `loan_id, account_number, member {id, name, code}, branch {id, name}, display_status, oldest_late_due_date, days_past_due, bucket, is_past_due, outstanding_principal, total_outstanding, past_due_amount`. `days_past_due` counts from the oldest unpaid period's bare due date (0 and `current` when none is due before `as_of`); `is_past_due` and `past_due_amount` follow the grace rule, so a loan can be in `1_30` and not past due. `group=member` answers member rows: `member, loan_count, outstanding_principal, total_outstanding, past_due_amount, days_past_due, bucket`, the bucket and days of the member's worst loan; the bucket filter reads that bucket. `meta.totals` (`count`, `outstanding_principal`, `total_outstanding`, `past_due_amount`) covers every matching row; `meta.stats` counts rows per bucket, ignoring the bucket filter.",
        tags: ['Accounting'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'as_of', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date'), description: 'YYYY-MM-DD. Defaults to today.'),
            new OA\Parameter(name: 'branch_id', in: 'query', required: false, description: 'One of the caller\'s branches (another answers 403). Without it, every branch the caller holds.', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'bucket', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ReceivableAgingBuilder::BUCKETS)),
            new OA\Parameter(name: 'past_due_only', in: 'query', required: false, description: 'Only loans past due by the grace rule. `1`, `0`, `true` or `false`.', schema: new OA\Schema(type: 'string', enum: ['1', '0', 'true', 'false'])),
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Account number, member name or member code. `%`, `_` and `\\` match literally.', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'group', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: LoanAgingBuilder::GROUPS, default: 'loan')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 25)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The paginator envelope {data, links, meta}, with meta.totals and meta.stats'),
            new OA\Response(response: 403, description: 'Missing accounting:view, or a branch outside the caller\'s'),
            new OA\Response(response: 422, description: 'A bad filter'),
        ],
    )]
    public function loans(): AnonymousResourceCollection
    {
        $this->authorize('accounting:view');

        $filters = request()->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'integer'],
            'bucket' => ['nullable', Rule::in(ReceivableAgingBuilder::BUCKETS)],
            // Query strings carry text, so `true` and `false` are accepted
            // beside 1 and 0.
            'past_due_only' => ['nullable', Rule::in(['1', '0', 'true', 'false'])],
            'search' => ['nullable', 'string', 'max:100'],
            'group' => ['nullable', Rule::in(LoanAgingBuilder::GROUPS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.LoanAgingBuilder::MAX_PER_PAGE],
        ]);

        $result = $this->loanAging->page(
            $this->asOf($filters),
            $this->branchScope($filters['branch_id'] ?? null),
            [
                'bucket' => $filters['bucket'] ?? null,
                'past_due_only' => filter_var($filters['past_due_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'search' => $filters['search'] ?? null,
            ],
            $filters['group'] ?? 'loan',
            (int) ($filters['page'] ?? 1),
            (int) ($filters['per_page'] ?? LoanAgingBuilder::DEFAULT_PER_PAGE),
            Loan::visibleTo(request()->user()),
        );

        return JsonResource::collection($result['rows']->withQueryString())
            ->additional(['meta' => [
                'totals' => $result['totals'],
                'stats' => $result['stats'],
            ]]);
    }

    /**
     * The report date, defaulting to today in the application's timezone.
     *
     * @param  array<string, mixed>  $filters
     */
    private function asOf(array $filters): string
    {
        $asOf = $filters['as_of'] ?? null;

        return $asOf === null || trim((string) $asOf) === ''
            ? now()->toDateString()
            : (string) $asOf;
    }
}
