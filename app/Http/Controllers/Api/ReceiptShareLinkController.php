<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReceiptShareLinkResource;
use App\Models\Repayment;
use App\Services\ReceiptShareLinks\ReceiptShareLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Receipt share links, staff side (finish run round 2, decision 6). Each
 * route needs `payments:view` and the payment's branch: `{repayment}` binds
 * only to a payment on a loan the caller can see (Repayment's route binding),
 * so another branch's receipt answers 404. While the server switch is off
 * every route answers 404 (EnsureReceiptShareLinksEnabled).
 */
class ReceiptShareLinkController extends Controller
{
    public function __construct(private readonly ReceiptShareLinks $links) {}

    #[OA\Get(
        path: '/api/repayments/{repayment}/share-link',
        summary: 'The receipt\'s share link status',
        description: 'The newest share link of the receipt (`active`, `expired` or `revoked`), or `link: null` when it has none. Never the token. 404 while RECEIPT_SHARE_LINKS_ENABLED is off.',
        tags: ['Repayments'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'repayment', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: '{data: {link: ReceiptShareLink|null}}'),
            new OA\Response(response: 403, description: 'Missing payments:view'),
            new OA\Response(response: 404, description: 'Not found, another branch\'s receipt, or the switch is off'),
        ],
    )]
    public function show(Repayment $repayment): JsonResponse
    {
        $this->authorize('payments:view');

        $link = $this->links->latest($repayment);

        return response()->json(['data' => ['link' => $link ? (new ReceiptShareLinkResource($link))->resolve() : null]]);
    }

    #[OA\Post(
        path: '/api/repayments/{repayment}/share-link',
        summary: 'Make a share link for the receipt',
        description: 'Answers the link and its token once: only the token\'s SHA-256 is stored, so it can\'t be shown again. The link opens /r/{token} for 30 days. 409 while the receipt already has a working link. Audited.',
        tags: ['Repayments'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'repayment', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 201, description: '{message, data: {token, link: ReceiptShareLink}}'),
            new OA\Response(response: 403, description: 'Missing payments:view'),
            new OA\Response(response: 404, description: 'Not found, another branch\'s receipt, or the switch is off'),
            new OA\Response(response: 409, description: 'The receipt already has a working link'),
        ],
    )]
    public function store(Request $request, Repayment $repayment): JsonResponse
    {
        $this->authorize('payments:view');

        [$link, $token] = $this->links->create($repayment, $request->user());

        return response()->json([
            'message' => 'Share link made. Copy it now: it can\'t be shown again.',
            'data' => ['token' => $token, 'link' => (new ReceiptShareLinkResource($link))->resolve()],
        ], 201)->header('Cache-Control', 'no-store, private');
    }

    #[OA\Patch(
        path: '/api/repayments/{repayment}/share-link/revoke',
        summary: 'Revoke the receipt\'s working share link',
        description: 'The link stops opening at once. 409 when the receipt has no working link. Audited.',
        tags: ['Repayments'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'repayment', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: '{message, data: {link: ReceiptShareLink}}'),
            new OA\Response(response: 403, description: 'Missing payments:view'),
            new OA\Response(response: 404, description: 'Not found, another branch\'s receipt, or the switch is off'),
            new OA\Response(response: 409, description: 'No working link to revoke'),
        ],
    )]
    public function revoke(Request $request, Repayment $repayment): JsonResponse
    {
        $this->authorize('payments:view');

        $link = $this->links->revoke($repayment, $request->user());

        return response()->json([
            'message' => 'Share link revoked. It no longer opens the receipt.',
            'data' => ['link' => (new ReceiptShareLinkResource($link))->resolve()],
        ]);
    }
}
