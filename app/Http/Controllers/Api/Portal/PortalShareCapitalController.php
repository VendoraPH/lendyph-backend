<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\MemberScope;
use App\Services\Portal\Pdf\PortalDownload;
use App\Services\Portal\Pdf\PortalStatements;
use App\Services\Portal\Pdf\StatementRange;
use App\Services\Portal\Read\PortalPage;
use App\Services\Portal\Read\ShareCapitalReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The member's share capital (portal design §3): the Subsidiary Ledger's
 * figures and the server's running balance.
 */
class PortalShareCapitalController extends Controller
{
    public function __construct(
        private readonly MemberScope $scope,
        private readonly ShareCapitalReader $shareCapital,
    ) {}

    #[OA\Get(
        path: '/api/portal/share-capital',
        summary: 'The member\'s share capital',
        description: 'Signed-in portal token; feature `share_capital`. {paid_up (the ledger balance), pledged, remaining_pledge (pledged less paid up, never below ₱0.00), total_contributions (every credit), last_contribution{date, amount} or null}.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The share capital')],
    )]
    public function show(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->shareCapital->summary()]);
    }

    #[OA\Get(
        path: '/api/portal/share-capital/transactions',
        summary: 'The member\'s share capital entries',
        description: 'Signed-in portal token; feature `share_capital`. Newest first, paged (per_page ≤ 50): [{date, reference, description, debit, credit, running_balance (the balance after the entry, by date then posting order)}].',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, maximum: 50)),
        ],
        responses: [new OA\Response(response: 200, description: 'The entries')],
    )]
    public function transactions(Request $request): JsonResponse
    {
        $perPage = PortalPage::perPage($request->validate(['per_page' => PortalPage::PER_PAGE_RULES])['per_page'] ?? null);
        $page = $this->shareCapital->transactions($perPage);

        return PortalPage::json($page, $this->shareCapital->rows(collect($page->items())));
    }

    #[OA\Get(
        path: '/api/portal/share-capital/statement',
        summary: 'The member\'s Share Capital Statement (PDF)',
        description: 'Signed-in portal token; features `share_capital` and `statements`. `from` and `to` (YYYY-MM-DD) are required, `to` no later than today (Manila), at most 5 years apart (422 in words). Built by the staff Share Capital Statement, as an A4 PDF attachment. At most 10 PDFs a minute. Audited.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [new OA\Response(response: 200, description: 'application/pdf'), new OA\Response(response: 422, description: 'A bad range')],
    )]
    public function statement(Request $request, PortalStatements $statements, PortalDownload $download): Response
    {
        $range = StatementRange::from($request, true);
        $member = $this->scope->portalUser()->borrower;

        return $download->statement(
            $statements->shareCapitalStatement($member, (string) $range['from'], (string) $range['to']),
            $member,
            ['kind' => 'share_capital_statement', 'from' => $range['from'], 'to' => $range['to']],
        );
    }

    #[OA\Get(
        path: '/api/portal/share-capital/certificate',
        summary: 'The member\'s Share Capital Certificate (PDF)',
        description: 'Signed-in portal token; features `share_capital` and `statements`. `as_of` (YYYY-MM-DD) is required and no later than today (Manila). The paid-up share capital on that day, from the staff Share Capital Statement, as an A4 PDF attachment. At most 10 PDFs a minute. Audited.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'as_of', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [new OA\Response(response: 200, description: 'application/pdf'), new OA\Response(response: 422, description: 'A bad day')],
    )]
    public function certificate(Request $request, PortalStatements $statements, PortalDownload $download): Response
    {
        $asOf = StatementRange::asOf($request);
        $member = $this->scope->portalUser()->borrower;

        return $download->statement(
            $statements->shareCapitalCertificate($member, $asOf),
            $member,
            ['kind' => 'share_capital_certificate', 'as_of' => $asOf],
        );
    }
}
