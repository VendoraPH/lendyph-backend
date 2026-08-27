<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShareCapital\ShareCapitalBulkEntryRequest;
use App\Http\Requests\ShareCapital\ShareCapitalManualEntryRequest;
use App\Http\Requests\ShareCapital\UpdateShareCapitalPledgeRequest;
use App\Http\Resources\ShareCapitalLedgerResource;
use App\Http\Resources\ShareCapitalPledgeResource;
use App\Models\Borrower;
use App\Models\ShareCapitalPledge;
use App\Services\ShareCapitalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class ShareCapitalPledgeController extends Controller
{
    public function __construct(private ShareCapitalService $shareCapitalService) {}

    /**
     * Refuses any write against a pledge whose borrower is not a member.
     *
     * Borrower::booted() creates a pledge row for EVERY borrower, `pending`
     * applicants included, and index() is now member-scoped — so a non-member's
     * pledge can still be given `auto_credit`, a new amount or a ledger entry
     * by a hand-made request, and the row it lands on is invisible in the one
     * screen that would have shown it. processAutoCredit() refuses to post for
     * those borrowers today, which makes such a flag inert rather than
     * harmless: it is unobservable state that a single future change to the
     * write path converts into real ledger credits for somebody who never
     * joined the cooperative.
     *
     * Nothing legitimate needs this write. approveRegistration() never touches
     * the pledge — both it and `borrower_code` are written at submission time —
     * and the registration review screen only renders `pledge_amount`.
     *
     * 422 rather than 403: the pledge exists and the caller holds the right
     * permission; it is the borrower's registration state that is wrong. That
     * is the same shape of refusal approveRegistration() already returns.
     */
    private function assertBorrowerIsMember(ShareCapitalPledge $pledge): void
    {
        $status = $pledge->borrower?->status;

        // Phrased as "prove membership to pass" rather than "reject the known
        // bad list", so an unreadable borrower fails CLOSED. Testing the
        // rejection first let a null status through: `in_array(null, [...],
        // true)` is false, so an orphaned pledge would have been treated as a
        // member and allowed the write. `borrower_id` is a restrictOnDelete
        // foreign key so that cannot happen today — but a guard should not
        // depend on a constraint declared in a different file to be safe.
        if ($status !== null && ! in_array($status, Borrower::NON_MEMBER_STATUSES, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'borrower_id' => $status === null
                ? 'This pledge is not attached to a borrower, so share capital cannot be recorded against it.'
                : "This borrower's registration is {$status}, so they are not a member yet. "
                    .'Share capital can only be recorded once the registration is approved.',
        ]);
    }

    #[OA\Get(
        path: '/api/pledges',
        summary: 'List share capital pledges',
        description: <<<'DESC'
Paginated list of share capital pledges, **scoped to members**.

A pledge row is auto-created for every borrower on registration, so this list
excludes borrowers whose registration is still `pending` or was `rejected`.
Members in poor standing (`inactive`, `blacklisted`) ARE included — they still
hold share capital. `ShareCapitalPledgeResource` exposes no borrower status, so
a client cannot reproduce this filter itself.
DESC,
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'schedule', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'auto_credit', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [new OA\Response(response: 200, description: 'Pledge list')],
    )]
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('share_capital:view');

        $pledges = ShareCapitalPledge::with('borrower')
            ->forMembers()
            ->withMax('borrowerLedgerEntries as last_transaction_date', 'date')
            ->when(request('schedule'), fn ($q, $s) => $q->where('schedule', $s))
            ->when(request()->has('auto_credit'), fn ($q) => $q->where('auto_credit', filter_var(request('auto_credit'), FILTER_VALIDATE_BOOLEAN)))
            ->when(request('search'), fn ($q, $search) => $q->whereHas('borrower', fn ($bq) => $bq->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")))
            ->orderBy('id')
            ->paginate(min((int) request('per_page', 15), 100));

        return ShareCapitalPledgeResource::collection($pledges);
    }

    #[OA\Put(
        path: '/api/pledges/{pledge}',
        summary: 'Update pledge amount and schedule',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'pledge', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(response: 200, description: 'Pledge updated'),
            new OA\Response(response: 422, description: 'The pledge belongs to a borrower whose registration is still `pending` or was `rejected`'),
        ],
    )]
    public function update(UpdateShareCapitalPledgeRequest $request, ShareCapitalPledge $pledge): ShareCapitalPledgeResource
    {
        $this->assertBorrowerIsMember($pledge);

        $pledge->update($request->validated());
        $pledge->load('borrower')->loadMax('borrowerLedgerEntries as last_transaction_date', 'date');

        return new ShareCapitalPledgeResource($pledge);
    }

    #[OA\Patch(
        path: '/api/pledges/{pledge}/auto-credit',
        summary: 'Toggle auto-credit status',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'pledge', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Auto-credit toggled'),
            new OA\Response(response: 422, description: 'The pledge belongs to a borrower whose registration is still `pending` or was `rejected`'),
        ],
    )]
    public function toggleAutoCredit(ShareCapitalPledge $pledge): JsonResponse
    {
        $this->authorize('share_capital:update');

        $this->assertBorrowerIsMember($pledge);

        $pledge->update(['auto_credit' => ! $pledge->auto_credit]);

        return response()->json([
            'message' => 'Auto-credit '.($pledge->auto_credit ? 'enabled' : 'disabled').'.',
            'auto_credit' => $pledge->auto_credit,
        ]);
    }

    #[OA\Post(
        path: '/api/pledges/{pledge}/entries',
        summary: 'Create manual ledger entry for a pledge',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'pledge', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(response: 201, description: 'Entry created'),
            new OA\Response(response: 422, description: 'The pledge belongs to a borrower whose registration is still `pending` or was `rejected`'),
        ],
    )]
    public function manualEntry(ShareCapitalManualEntryRequest $request, ShareCapitalPledge $pledge): JsonResponse
    {
        $this->assertBorrowerIsMember($pledge);

        $entry = $this->shareCapitalService->createManualEntry(
            $pledge,
            (float) $request->amount,
            $request->type,
            $request->date,
            $request->user(),
            $request->description,
        );

        $entry->load('borrower', 'createdByUser');

        return (new ShareCapitalLedgerResource($entry))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Post(
        path: '/api/pledges/bulk-entries',
        summary: 'Create bulk manual ledger entries',
        tags: ['Share Capital'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent),
        responses: [
            new OA\Response(response: 201, description: 'Bulk entries created'),
            new OA\Response(response: 422, description: 'One of the pledges belongs to a borrower whose registration is still `pending` or was `rejected`'),
        ],
    )]
    public function bulkEntry(ShareCapitalBulkEntryRequest $request): JsonResponse
    {
        /**
         * Same member gate as manualEntry(), which is the point: a guard that
         * covers only the single-entry route is bypassed by posting the same
         * pledge id to this one instead. Resolved in one query for the whole
         * batch and checked before any work starts, so the caller gets a single
         * 422 naming the problem rather than a rolled-back transaction.
         */
        ShareCapitalPledge::with('borrower')
            ->whereIn('id', array_column($request->entries, 'pledge_id'))
            ->get()
            ->each(fn (ShareCapitalPledge $pledge) => $this->assertBorrowerIsMember($pledge));

        $entries = $this->shareCapitalService->bulkManualEntries(
            $request->entries,
            $request->user(),
        );

        return response()->json([
            'message' => "Created {$entries->count()} ledger entries.",
            'count' => $entries->count(),
        ], 201);
    }
}
