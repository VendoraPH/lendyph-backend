<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\SavingsTransaction;
use App\Services\Portal\Pdf\PortalDownload;
use App\Services\Portal\Pdf\PortalStatements;
use App\Services\Portal\Pdf\StatementRange;
use App\Services\Portal\Read\PortalPage;
use App\Services\Portal\Read\SavingsReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The member's savings accounts and time deposits (portal design §3). Every
 * figure is the savings module's; `withdrawable` is the server's, with the
 * maintaining balance taken off once. Another member's record answers 404.
 */
class PortalSavingsController extends Controller
{
    private const ACCOUNT_FIELDS = '{id, account_number, product_name, status, ledger_balance, held (holds other than the member\'s own withdrawal requests), pending_withdrawal (the member\'s withdrawal requests still held plus withdrawals waiting for a checker), maintaining_balance, available_balance (balance less holds, pending withdrawals and the maintaining balance, never below ₱0.00; GET /savings/accounts/{id} `available_balance`), withdrawable (the available balance on an active account, "0.00" on a dormant, frozen or closed one), annual_rate (as stored), accrued_interest, interest_credited_through, opened_on, branch_name, last_transaction_on}';

    private const TIME_DEPOSIT_FIELDS = '{id, certificate_number, product_name, principal, annual_rate, term_days, placement_date, maturity_date, days_remaining (null once settled), maturing_soon (30 days or fewer), gross_interest, withholding_tax, net_interest, maturity_amount, maturity_instruction, status}';

    public function __construct(private readonly SavingsReader $savings) {}

    #[OA\Get(
        path: '/api/portal/savings',
        summary: 'The member\'s savings accounts',
        description: 'Signed-in portal token; feature `savings`. Open accounts first: ['.self::ACCOUNT_FIELDS.'].',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The accounts')],
    )]
    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->savings->list()]);
    }

    #[OA\Get(
        path: '/api/portal/savings/{id}',
        summary: 'One of the member\'s savings accounts',
        description: 'Signed-in portal token; feature `savings`. '.self::ACCOUNT_FIELDS.'. 404 for another member\'s account.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The account'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function show(int $id): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->savings->account($this->savings->findAccount($id))]);
    }

    #[OA\Get(
        path: '/api/portal/savings/{id}/transactions',
        summary: 'A savings account\'s passbook',
        description: 'Signed-in portal token; feature `savings`. Newest first, paged (per_page ≤ 50): [{date, reference, type, debit, credit, balance_after}], filtered by business date (`from`, `to`), `type` and a reference `search`. `meta.opening_balance` is the balance before `from` ("0.00" without one).',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: SavingsTransaction::TYPES)),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, maximum: 50)),
        ],
        responses: [new OA\Response(response: 200, description: 'One page of the passbook'), new OA\Response(response: 404, description: 'Not found'), new OA\Response(response: 422, description: 'A bad filter')],
    )]
    public function transactions(Request $request, int $id): JsonResponse
    {
        $account = $this->savings->findAccount($id);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'type' => ['nullable', Rule::in(SavingsTransaction::TYPES)],
            'search' => ['nullable', 'string', 'max:64'],
            'per_page' => PortalPage::PER_PAGE_RULES,
        ]);

        $passbook = $this->savings->passbook($account, $filters, PortalPage::perPage($filters['per_page'] ?? null));

        return PortalPage::json($passbook['page'], $this->savings->passbookRows(collect($passbook['page']->items())), [
            'opening_balance' => $passbook['opening_balance'],
        ]);
    }

    #[OA\Get(
        path: '/api/portal/savings/{id}/statement',
        summary: 'A savings statement (PDF)',
        description: 'Signed-in portal token; features `savings` and `statements`. `from` and `to` (YYYY-MM-DD) are optional (the account\'s opening day through today), `to` no later than today (Manila), at most 5 years apart (422 in words). Built by the savings statement, as an A4 PDF attachment. At most 10 PDFs a minute. Audited.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [new OA\Response(response: 200, description: 'application/pdf'), new OA\Response(response: 404, description: 'Not found'), new OA\Response(response: 422, description: 'A bad range')],
    )]
    public function statement(Request $request, int $id, PortalStatements $statements, PortalDownload $download): Response
    {
        $account = $this->savings->findAccount($id);
        $range = StatementRange::from($request, false);
        $document = $statements->savingsStatement($account, $range['from'], $range['to']);

        return $download->statement($document, $account, ['kind' => 'savings_statement', 'from' => $range['from'], 'to' => $range['to']]);
    }

    #[OA\Get(
        path: '/api/portal/time-deposits',
        summary: 'The member\'s time deposits',
        description: 'Signed-in portal token; feature `time_deposits`. Newest placement first: ['.self::TIME_DEPOSIT_FIELDS.'], the figures of GET /savings/time-deposits/{id}.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The time deposits')],
    )]
    public function timeDeposits(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->savings->timeDeposits()]);
    }

    #[OA\Get(
        path: '/api/portal/time-deposits/{id}',
        summary: 'One of the member\'s time deposits',
        description: 'Signed-in portal token; feature `time_deposits`. '.self::TIME_DEPOSIT_FIELDS.'. 404 for another member\'s placement.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'The time deposit'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function timeDeposit(int $id): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->savings->timeDeposit($this->savings->findTimeDeposit($id))]);
    }

    #[OA\Get(
        path: '/api/portal/time-deposits/{id}/certificate',
        summary: 'A time deposit certificate (PDF)',
        description: 'Signed-in portal token; features `time_deposits` and `statements`. The placement\'s figures as an A4 PDF attachment. At most 10 PDFs a minute. Audited.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'application/pdf'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function certificate(int $id, PortalStatements $statements, PortalDownload $download): Response
    {
        $deposit = $this->savings->findTimeDeposit($id);

        return $download->statement($statements->timeDepositCertificate($deposit), $deposit, ['kind' => 'time_deposit_certificate']);
    }
}
