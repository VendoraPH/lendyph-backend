<?php

namespace App\Http\Controllers\Api\LoanOrigination;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoanOrigination\OriginationAccess;
use App\Http\Requests\LoanOrigination\StoreOriginationNoticeRequest;
use App\Http\Resources\LoanOrigination\OriginationNoticeResource;
use App\Models\OriginationNotice;
use App\Services\LoanOrigination\OriginationNotices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The notices applicants accept (origination decision 9). Versions are
 * published and never edited or removed: there is no update or delete route.
 */
class OriginationNoticeController extends Controller
{
    public function __construct(private readonly OriginationNotices $notices) {}

    #[OA\Get(
        path: '/api/loan-origination/notices',
        summary: 'Online application notices',
        description: '`current` holds the newest version of each type (or null), `missing` the required notices not published yet, in words, and `versions` every version, newest first.',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The notices'),
            new OA\Response(response: 403, description: 'Missing loan_origination:view and loan_origination:settings'),
        ],
    )]
    public function index(Request $request): JsonResponse
    {
        abort_unless(OriginationAccess::canRead($request->user()), 403, OriginationAccess::READ_REFUSAL);

        $current = array_map(
            fn (?OriginationNotice $notice) => $notice === null ? null : new OriginationNoticeResource($notice->load('publisher')),
            $this->notices->currentAll(),
        );

        return response()->json(['success' => true, 'data' => [
            'current' => $current,
            'missing' => $this->notices->missingRequired(),
            'versions' => OriginationNoticeResource::collection(OriginationNotice::query()->with('publisher')->orderByDesc('id')->get()),
        ]]);
    }

    #[OA\Get(
        path: '/api/loan-origination/notices/{notice}',
        summary: 'One notice version',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'notice', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The notice'),
            new OA\Response(response: 403, description: 'Missing loan_origination:view and loan_origination:settings'),
            new OA\Response(response: 404, description: 'No such notice'),
        ],
    )]
    public function show(Request $request, OriginationNotice $notice): JsonResponse
    {
        abort_unless(OriginationAccess::canRead($request->user()), 403, OriginationAccess::READ_REFUSAL);

        return response()->json(['success' => true, 'data' => new OriginationNoticeResource($notice->load('publisher'))]);
    }

    #[OA\Post(
        path: '/api/loan-origination/notices',
        summary: 'Publish a notice version',
        description: 'Adds a version of the privacy notice, terms, declaration or credit authorization; it becomes that type\'s current one. `final` marks the owner\'s approved wording. Audited (the text itself is left out of the audit row; its hash is kept).',
        tags: ['Loan Origination'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['type', 'version', 'body', 'final'], properties: [
            new OA\Property(property: 'type', type: 'string', enum: OriginationNotice::TYPES),
            new OA\Property(property: 'version', type: 'string', example: '2026.1'),
            new OA\Property(property: 'title', type: 'string', nullable: true),
            new OA\Property(property: 'body', type: 'string'),
            new OA\Property(property: 'final', type: 'boolean'),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The published version'),
            new OA\Response(response: 403, description: 'Missing loan_origination:settings or branches:view_all (company-wide)'),
            new OA\Response(response: 422, description: 'An unknown type, or a version the type already has, in words'),
        ],
    )]
    public function store(StoreOriginationNoticeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $notice = $this->notices->publish($data['type'], $data['version'], $data['body'], $data['title'] ?? null, (bool) $data['final'], $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Notice published.',
            'data' => new OriginationNoticeResource($notice->load('publisher')),
        ], 201);
    }
}
