<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Repayment;
use App\Services\ReceiptShareLinks\PublicReceipt;
use App\Services\ReceiptShareLinks\ReceiptShareLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use OpenApi\Attributes as OA;

/**
 * A shared receipt, public side (finish run round 2, decision 6): no sign-in,
 * a token in the address, its own limiter, and `no-store`. A staff or member
 * token sent along is ignored. Unknown, expired, revoked and malformed tokens
 * get the same 404 as the switch being off.
 */
class PublicReceiptController extends Controller
{
    #[OA\Get(
        path: '/api/public/receipts/{token}',
        summary: 'A shared payment receipt, masked',
        description: 'Public. The receipt a working share link opens: the cooperative\'s name and logo path, the receipt number, payment date, amount paid and its split (2-decimal strings), the member as initials and surname, the method in words with the last 4 characters of its reference, and `status` `voided` with `voided_on` when voided. Never contacts, account numbers, balances, the next due date or other loans. Each view is audited as the system, with a hash of the address. The same 404 for an unknown, expired or revoked link and while RECEIPT_SHARE_LINKS_ENABLED is off.',
        tags: ['Repayments'],
        parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: '{data: {cooperative, receipt_number, payment_date, member_name, method, reference_last4, amount_paid, principal_applied, interest_applied, penalty_applied, insurance_applied, overpayment, build_up_credited, overpayment_kept, status, voided_on}}'),
            new OA\Response(response: 404, description: 'Not found'),
            new OA\Response(response: 429, description: 'Too many views from one address, or of one link (60 an hour)'),
        ],
    )]
    public function __invoke(Request $request, string $token, ReceiptShareLinks $links, PublicReceipt $receipt): JsonResponse
    {
        $link = $links->resolve($token);
        $repayment = $link === null ? null : Repayment::query()->find($link->repayment_id);

        if ($link === null || $repayment === null) {
            return ReceiptShareLinks::notFound();
        }

        // Each link answers at most 60 views an hour, whatever the addresses,
        // so rotating addresses can't flood the audit trail with view rows.
        $views = ReceiptShareLinks::linkViewsKey($link);
        if (RateLimiter::tooManyAttempts($views, ReceiptShareLinks::VIEWS_PER_LINK_PER_HOUR)) {
            return ReceiptShareLinks::tooManyViews(['Retry-After' => RateLimiter::availableIn($views)]);
        }
        RateLimiter::hit($views, 3600);

        $shown = $receipt->present($repayment, $request);
        $links->recordView($link, $repayment, $request);

        return response()->json(['data' => $shown]);
    }
}
