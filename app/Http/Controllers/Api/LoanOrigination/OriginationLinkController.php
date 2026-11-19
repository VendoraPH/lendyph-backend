<?php

namespace App\Http\Controllers\Api\LoanOrigination;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoanOrigination\OriginationAccess;
use App\Http\Requests\LoanOrigination\StoreOriginationLinkRequest;
use App\Http\Requests\LoanOrigination\UpdateOriginationLinkRequest;
use App\Http\Resources\LoanOrigination\OriginationLinkResource;
use App\Models\OriginationLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

/**
 * Public application links (origination decisions 16 and 17). Staff see and
 * manage only their own branches' links; a link for every branch needs
 * `branches:view_all`, and another branch's link answers 404. The token and
 * the visit, start and submission counters are the server's.
 */
class OriginationLinkController extends Controller
{
    private const FIELDS = ['name', 'active', 'is_test', 'branch_id', 'expires_at', 'display_name', 'contact_phone', 'contact_email'];

    #[OA\Get(
        path: '/api/loan-origination/links',
        summary: 'Public application links',
        description: 'The caller\'s branches\' links, newest first; links for every branch only with branches:view_all. `url` is null while LOAN_ORIGINATION_PUBLIC_URL isn\'t one of CORS_ALLOWED_ORIGINS. `status` is active, disabled or expired, with `status_label` in words.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The links'),
            new OA\Response(response: 403, description: 'Missing loan_origination:view and loan_origination:settings'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        abort_unless(OriginationAccess::canRead($request->user()), 403, OriginationAccess::READ_REFUSAL);

        $links = OriginationLink::query()->visibleTo($request->user())->with(['branch', 'loanProducts'])->orderByDesc('id')->get();

        return response()->json(['success' => true, 'data' => OriginationLinkResource::collection($links)]);
    }

    #[OA\Get(
        path: '/api/loan-origination/links/{link}',
        summary: 'One public application link',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'link', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The link'),
            new OA\Response(response: 403, description: 'Missing loan_origination:view and loan_origination:settings'),
            new OA\Response(response: 404, description: 'No such link, or another branch\'s'),
        ],
    )]
    public function show(Request $request, OriginationLink $link): JsonResponse
    {
        abort_unless(OriginationAccess::canRead($request->user()), 403, OriginationAccess::READ_REFUSAL);

        return response()->json(['success' => true, 'data' => new OriginationLinkResource($link->load(['branch', 'loanProducts']))]);
    }

    #[OA\Post(
        path: '/api/loan-origination/links',
        summary: 'Create a public application link',
        description: 'The server makes the random token. No `loan_product_ids` means every product offered online. Audited.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'The new link'),
            new OA\Response(response: 403, description: 'Missing loan_origination:settings'),
            new OA\Response(response: 422, description: 'A branch outside the caller\'s, no branch without branches:view_all, a past expiry or an unknown product, in words'),
        ],
    )]
    public function store(StoreOriginationLinkRequest $request): JsonResponse
    {
        $data = $request->validated();

        $link = DB::transaction(function () use ($data, $request): OriginationLink {
            $link = OriginationLink::query()->create([
                ...Arr::only($data, self::FIELDS),
                'token' => OriginationLink::newToken(),
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);

            $link->loanProducts()->sync($data['loan_product_ids'] ?? []);

            return $link;
        });

        return response()->json([
            'success' => true,
            'message' => 'Link created.',
            'data' => new OriginationLinkResource($link->refresh()->load(['branch', 'loanProducts'])),
        ], 201);
    }

    #[OA\Patch(
        path: '/api/loan-origination/links/{link}',
        summary: 'Change a public application link',
        description: 'Any of name, active, is_test, branch_id, loan_product_ids, expires_at, display_name, contact_phone and contact_email. The token and counters never change. Audited.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'link', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The changed link'),
            new OA\Response(response: 403, description: 'Missing loan_origination:settings'),
            new OA\Response(response: 404, description: 'No such link, or another branch\'s'),
            new OA\Response(response: 422, description: 'As on create, in words'),
        ],
    )]
    public function update(UpdateOriginationLinkRequest $request, OriginationLink $link): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $request, $link): void {
            $link->fill(Arr::only($data, self::FIELDS));

            if ($link->isDirty()) {
                $link->updated_by = $request->user()->id;
                $link->save();
            }

            if (array_key_exists('loan_product_ids', $data)) {
                $link->loanProducts()->sync($data['loan_product_ids'] ?? []);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Link saved.',
            'data' => new OriginationLinkResource($link->refresh()->load(['branch', 'loanProducts'])),
        ]);
    }
}
