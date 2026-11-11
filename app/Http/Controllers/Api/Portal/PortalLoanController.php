<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\Pdf\PortalDownload;
use App\Services\Portal\Pdf\PortalStatements;
use App\Services\Portal\Read\LoanReader;
use App\Services\Portal\Read\PortalPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The member's loans (portal design §1, §3): their own ever-released loans
 * only, never as co-maker and never a draft; every figure the staff loan
 * screens show. Another member's loan answers 404.
 */
class PortalLoanController extends Controller
{
    public function __construct(private readonly LoanReader $loans) {}

    #[OA\Get(
        path: '/api/portal/loans',
        summary: 'The member\'s loans',
        description: 'Signed-in portal token; feature `loans`. Ever-released loans only, newest first: [{id, loan_account_number, product_name, display_status (the server\'s, `past_due` for a late released or ongoing loan), release_date, maturity_date, principal_amount, total_outstanding (everything owed), outstanding_principal, overdue_amount, next_due_date, next_due_amount, paid_percent (principal paid as a whole percent, floored)}]. Money is a two-decimal peso string equal to GET /loans/{id}/summary.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The loans'), new OA\Response(response: 403, description: 'Loans are switched off')],
    )]
    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->loans->list()]);
    }

    #[OA\Get(
        path: '/api/portal/loans/{id}',
        summary: 'One of the member\'s loans',
        description: 'Signed-in portal token; feature `loans`. The list row plus term, term_unit, frequency, interest_rate (as stored, per interest_rate_frequency, never converted or rounded), interest_rate_frequency, interest_method, outstanding_interest, outstanding_penalty, insurance_owed, total_repaid, principal_paid, interest_paid, penalty_paid and penalty_months (null until the loan summary carries it). Never payment presets, the payoff figure or the restructurable balance. 404 for another member\'s loan, a co-maker\'s loan or a draft.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The loan'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function show(int $id): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->loans->detail($this->loans->find($id))]);
    }

    #[OA\Get(
        path: '/api/portal/loans/{id}/schedule',
        summary: 'A loan\'s schedule with what each period still owes',
        description: 'Signed-in portal token; feature `loans`. The Amortization Balance tab\'s figures: {periods[]{period_number, due_date, status (paid, partial, due, past_due, upcoming or closed; Manila today), principal{due, paid, balance}, interest{due, paid, balance}, penalty{charged, paid, balance}, balance}, totals{principal, interest, penalty, paid, insurance_owed, balance (equals the loan\'s total_outstanding), overdue}}.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The schedule'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function schedule(int $id): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->loans->schedule($this->loans->find($id))]);
    }

    #[OA\Get(
        path: '/api/portal/loans/{id}/payments',
        summary: 'A loan\'s payments',
        description: 'Signed-in portal token; feature `loans`. Posted (not voided) payments, newest first, paged (per_page ≤ 50): [{receipt_number, loan_account_number, payment_date, amount_paid, principal, interest, penalty, insurance, build_up, method, reference_number}].',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, maximum: 50)),
        ],
        responses: [new OA\Response(response: 200, description: 'The payments'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function payments(Request $request, int $id): JsonResponse
    {
        $loan = $this->loans->find($id);
        $perPage = PortalPage::perPage($request->validate(['per_page' => PortalPage::PER_PAGE_RULES])['per_page'] ?? null);
        $page = $this->loans->payments($loan, $perPage);

        return PortalPage::json($page, $this->loans->paymentRows(collect($page->items())));
    }

    #[OA\Get(
        path: '/api/portal/payments',
        summary: 'Every payment on the member\'s loans',
        description: 'Signed-in portal token; feature `loans`. The same rows as a loan\'s payments, over all of the member\'s loans, newest first, paged (per_page ≤ 50).',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, maximum: 50)),
        ],
        responses: [new OA\Response(response: 200, description: 'The payments')],
    )]
    public function allPayments(Request $request): JsonResponse
    {
        $perPage = PortalPage::perPage($request->validate(['per_page' => PortalPage::PER_PAGE_RULES])['per_page'] ?? null);
        $page = $this->loans->payments(null, $perPage);

        return PortalPage::json($page, $this->loans->paymentRows(collect($page->items())));
    }

    #[OA\Get(
        path: '/api/portal/loans/{id}/statement',
        summary: 'A loan\'s Statement of Account (PDF)',
        description: 'Signed-in portal token; features `loans` and `statements`. The Statement of Account as of today, built by the staff statement, as an A4 PDF attachment. At most 10 PDFs a minute. Audited.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'application/pdf'), new OA\Response(response: 404, description: 'Not found'), new OA\Response(response: 429, description: 'Too many PDFs')],
    )]
    public function statement(int $id, PortalStatements $statements, PortalDownload $download): Response
    {
        $loan = $this->loans->find($id);

        return $download->statement($statements->loan($loan), $loan, ['kind' => 'loan_statement', 'as_of' => now()->toDateString()]);
    }
}
