<?php

namespace App\Http\Controllers\Api\Cda;

use App\Http\Controllers\Controller;
use App\Http\Resources\Cda\CdaPresenter;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\CdaReportingPeriod;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Classification\LoanClassification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Loan classification and the provision gap for a CDA year (CDA design §7).
 * Read only: there are no write routes. Needs `cda:view` and
 * `branches:view_all`; the loan-by-loan rows also need `loans:view`.
 */
class CdaLoanClassificationController extends Controller
{
    private const MAX_PER_PAGE = 100;

    public function __construct(private readonly CdaAccess $access) {}

    #[OA\Get(
        path: '/api/cda/periods/{year}/loan-classification',
        summary: 'Loan classification and provision gap for a CDA year',
        description: 'Needs `cda:view` and `branches:view_all`. Reads the year-end capture (`basis: year_end_capture`, with `captured_at`); without one the figures are computed from current balances (`basis: live`, `provisional: true`, and a `warning` in words). `rows`: one per aging bucket (Current and the five past-due buckets) with the loan count, outstanding principal, the required rate (a percent string, never rounded) and the required allowance. `existing_allowance` is the 1200 balance, `provision_gap` = required − existing. `unpaid_interest_due` is a memo (cash-basis books). `loans` (paged, `meta`) needs `loans:view`, otherwise it is null with `loans_visible: false`. Money is 2-decimal strings.',
        tags: ['CDA'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'year', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'bucket', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: LoanClassification::BUCKETS)),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The classification', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'success', type: 'boolean', example: true),
                new OA\Property(property: 'data', type: 'object', properties: [
                    new OA\Property(property: 'year', type: 'integer', example: 2026),
                    new OA\Property(property: 'as_of', type: 'string', format: 'date', example: '2026-12-31'),
                    new OA\Property(property: 'basis', type: 'string', enum: ['year_end_capture', 'live']),
                    new OA\Property(property: 'captured_at', type: 'string', format: 'date-time', nullable: true),
                    new OA\Property(property: 'provisional', type: 'boolean'),
                    new OA\Property(property: 'warning', type: 'string', nullable: true),
                    new OA\Property(property: 'rows', type: 'array', items: new OA\Items(properties: [
                        new OA\Property(property: 'bucket', type: 'string', example: '31_60'),
                        new OA\Property(property: 'classification', type: 'string', example: '31–60 days'),
                        new OA\Property(property: 'dpd_range', type: 'string', example: '31–60'),
                        new OA\Property(property: 'loan_count', type: 'integer'),
                        new OA\Property(property: 'outstanding_principal', type: 'string', example: '15000.00'),
                        new OA\Property(property: 'required_rate', type: 'string', example: '15.00'),
                        new OA\Property(property: 'required_allowance', type: 'string', example: '2250.00'),
                    ])),
                    new OA\Property(property: 'total_loan_count', type: 'integer'),
                    new OA\Property(property: 'total_outstanding_principal', type: 'string'),
                    new OA\Property(property: 'required_allowance', type: 'string'),
                    new OA\Property(property: 'existing_allowance', type: 'string'),
                    new OA\Property(property: 'provision_gap', type: 'string', description: 'Positive: under-provisioned'),
                    new OA\Property(property: 'unpaid_interest_due', type: 'string'),
                    new OA\Property(property: 'loans_visible', type: 'boolean'),
                    new OA\Property(property: 'loans', type: 'array', nullable: true, items: new OA\Items(type: 'object')),
                ]),
                new OA\Property(property: 'meta', type: 'object', nullable: true),
            ])),
            new OA\Response(response: 403, description: 'Missing cda:view or branches:view_all'),
            new OA\Response(response: 404, description: 'No period for that year'),
            new OA\Response(response: 422, description: 'An unknown bucket or a page size above 100'),
        ],
    )]
    public function show(Request $request, int $year, LoanClassification $classification): JsonResponse
    {
        $user = $request->user();
        $this->access->authorize($user, CdaAccess::VIEW);
        $input = $request->validate([
            'bucket' => ['nullable', 'string', Rule::in(LoanClassification::BUCKETS)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ], [
            'bucket.in' => 'Pick one of the aging buckets: '.implode(', ', LoanClassification::BUCKETS).'.',
            'page.*' => 'The page is a whole number from 1.',
            'per_page.max' => 'Show at most '.self::MAX_PER_PAGE.' loans a page.',
            'per_page.*' => 'The page size is a whole number from 1 to '.self::MAX_PER_PAGE.'.',
        ]);

        $period = CdaReportingPeriod::query()->where('year', $year)->first();
        abort_if($period === null, 404, "There is no CDA reporting period for {$year}.");

        $figures = $classification->for($period);
        $loansVisible = $user->can('loans:view');
        $data = [
            'year' => (int) $period->year,
            'as_of' => $figures['as_of'],
            'basis' => $figures['basis'],
            'captured_at' => $figures['captured_at'],
            'provisional' => $figures['provisional'],
            'warning' => $figures['warning'],
            'basis_note' => $figures['basis_note'],
            'rows' => array_map(fn (array $bucket): array => [
                'bucket' => $bucket['bucket'],
                'classification' => $bucket['label'],
                'dpd_range' => $bucket['dpd_range'],
                'loan_count' => $bucket['loan_count'],
                'outstanding_principal' => CdaPresenter::money($bucket['principal']),
                'required_rate' => $bucket['rate_percent'],
                'required_allowance' => CdaPresenter::money($bucket['required']),
            ], $figures['buckets']),
            'total_loan_count' => $figures['totals']['loan_count'],
            'total_outstanding_principal' => CdaPresenter::money($figures['totals']['principal']),
            'required_allowance' => CdaPresenter::money($figures['totals']['required']),
            'existing_allowance' => CdaPresenter::money($figures['totals']['booked']),
            'existing_allowance_unassigned' => CdaPresenter::money($figures['booked']['unassigned']),
            'provision_gap' => CdaPresenter::money($figures['totals']['gap']),
            'unpaid_interest_due' => CdaPresenter::money($figures['totals']['unpaid_interest_due']),
            'memo_note' => LoanClassification::MEMO_NOTE,
            'loans_visible' => $loansVisible,
            'loans' => null,
        ];

        if (! $loansVisible) {
            return response()->json(['success' => true, 'data' => $data, 'meta' => null]);
        }

        $rows = array_values(array_filter(
            $figures['loans'],
            fn (array $row) => ($input['bucket'] ?? null) === null || $row['bucket'] === $input['bucket'],
        ));
        usort($rows, fn (array $a, array $b) => [$b['days_past_due'], $a['loan_id']] <=> [$a['days_past_due'], $b['loan_id']]);

        $perPage = (int) ($input['per_page'] ?? 25);
        $page = (int) ($input['page'] ?? 1);
        $pageRows = array_slice($rows, ($page - 1) * $perPage, $perPage);
        $data['loans'] = $this->loanRows($pageRows);

        return response()->json(['success' => true, 'data' => $data, 'meta' => [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => count($rows),
            'last_page' => max(1, (int) ceil(count($rows) / $perPage)),
        ]]);
    }

    /**
     * Loan rows as the aging screens show them: the member's id, name and
     * code only, and money as 2-decimal strings.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function loanRows(array $rows): array
    {
        $members = Borrower::query()->whereIn('id', array_column($rows, 'member_id'))
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'borrower_code'])->keyBy('id');
        $branches = Branch::query()->whereIn('id', array_filter(array_column($rows, 'branch_id')))->pluck('name', 'id');

        return array_map(fn (array $row): array => [
            'loan_id' => (int) $row['loan_id'],
            'account_number' => $row['account_number'],
            'member' => [
                'id' => (int) $row['member_id'],
                'name' => $members->get($row['member_id'])?->full_name,
                'code' => $members->get($row['member_id'])?->borrower_code,
            ],
            'branch' => $row['branch_id'] === null ? null : ['id' => (int) $row['branch_id'], 'name' => $branches[$row['branch_id']] ?? null],
            'bucket' => $row['bucket'],
            'classification' => LoanClassification::LABELS[$row['bucket']] ?? $row['bucket'],
            'days_past_due' => (int) $row['days_past_due'],
            'oldest_late_due_date' => $row['oldest_late_due_date'],
            'is_past_due' => (bool) $row['is_past_due'],
            'outstanding_principal' => CdaPresenter::money((int) $row['outstanding_principal']),
            'total_outstanding' => CdaPresenter::money((int) $row['total_outstanding']),
            'past_due_amount' => CdaPresenter::money((int) $row['past_due_amount']),
            'unpaid_interest_due' => CdaPresenter::money((int) ($row['unpaid_interest_due'] ?? 0)),
        ], $rows);
    }
}
