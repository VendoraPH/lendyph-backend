<?php

namespace App\Http\Controllers\Api\Cic;

use App\Models\CicCapture;
use App\Services\Cic\Capture\CicCaptureService;
use App\Services\Cic\CicAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * CIC captures: the month-end schedule's, and staff's test (staging only)
 * and correction captures. A capture shows counts, status and checksum,
 * never a member.
 */
class CicCapturesController extends CicController
{
    public const PER_PAGE = 25;

    #[OA\Get(
        path: '/api/cic/captures',
        summary: 'CIC captures',
        description: 'Needs `cic:view` and `branches:view_all`. Newest reference date first, 25 a page. {data: [capture], meta: {current_page, last_page, per_page, total}}; a capture is {id, reference_date, kind, status, corrects_capture_id, subject_count, contract_count, event_count, checksum, started_at, finished_at, error, created_by: {id, name} or null (the schedule)}.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The captures'), new OA\Response(response: 403, description: 'No access')],
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorizeCic($request, CicAccess::VIEW);
        $request->validate(['page' => ['nullable', 'integer', 'min:1']], ['page.integer' => 'The page must be a whole number.', 'page.min' => 'The page must be 1 or more.']);

        $captures = CicCapture::query()->with('creator:id,first_name,last_name')
            ->orderByDesc('reference_date')->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $captures->getCollection()->map(fn (CicCapture $c) => self::summary($c))->all(),
            'meta' => [
                'current_page' => $captures->currentPage(),
                'last_page' => $captures->lastPage(),
                'per_page' => $captures->perPage(),
                'total' => $captures->total(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/cic/captures/{id}',
        summary: 'One CIC capture',
        description: 'Needs `cic:view` and `branches:view_all`. {data: capture}.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The capture'), new OA\Response(response: 403, description: 'No access'), new OA\Response(response: 404, description: 'No such capture')],
    )]
    public function show(Request $request, int $id): JsonResponse
    {
        $this->authorizeCic($request, CicAccess::VIEW);
        $capture = CicCapture::query()->with('creator:id,first_name,last_name')->find($id);
        abort_if($capture === null, 404, 'Not found.');

        return response()->json(['success' => true, 'data' => self::summary($capture)]);
    }

    #[OA\Post(
        path: '/api/cic/captures',
        summary: 'Take a test or correction capture',
        description: 'Needs `cic:prepare` and `branches:view_all`. {kind: test|correction, corrects_capture_id (correction only: a complete month-end capture)}. A test capture is dated today and only allowed while test captures are on (staging); a correction is dated on the capture it corrects. 201 {data: capture}; 500 {message, data: capture} when the capture failed.',
        tags: ['CIC'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 201, description: 'Captured'), new OA\Response(response: 403, description: 'No access'), new OA\Response(response: 422, description: 'Refused'), new OA\Response(response: 500, description: 'The capture failed')],
    )]
    public function store(Request $request, CicCaptureService $captures): JsonResponse
    {
        $user = $this->authorizeCic($request, CicAccess::PREPARE);

        $data = $request->validate([
            'kind' => ['required', Rule::in([CicCapture::KIND_TEST, CicCapture::KIND_CORRECTION])],
            'corrects_capture_id' => ['nullable', 'integer', 'min:1', 'required_if:kind,'.CicCapture::KIND_CORRECTION, 'prohibited_unless:kind,'.CicCapture::KIND_CORRECTION],
        ], [
            'kind.required' => 'Choose a test or correction capture.',
            'kind.in' => 'Choose a test or correction capture.',
            'corrects_capture_id.required_if' => 'Choose the month-end capture to correct.',
            'corrects_capture_id.integer' => 'Choose the month-end capture to correct.',
            'corrects_capture_id.min' => 'Choose the month-end capture to correct.',
            'corrects_capture_id.prohibited_unless' => 'Only a correction names a capture to correct.',
        ]);

        $corrects = null;

        if (isset($data['corrects_capture_id'])) {
            $corrects = CicCapture::query()->find((int) $data['corrects_capture_id']);
            if ($corrects === null) {
                throw ValidationException::withMessages(['corrects_capture_id' => 'Choose the month-end capture to correct.']);
            }
        }

        $date = $corrects !== null ? Carbon::parse($corrects->reference_date->toDateString()) : Carbon::today();
        $capture = $captures->capture($date, $data['kind'], $user, $corrects)->load('creator:id,first_name,last_name');

        if (! $capture->isComplete()) {
            return response()->json(['message' => $capture->error, 'data' => self::summary($capture)], 500);
        }

        return response()->json(['success' => true, 'data' => self::summary($capture)], 201);
    }

    /** @return array<string, mixed>|null */
    public static function summary(?CicCapture $capture): ?array
    {
        if ($capture === null) {
            return null;
        }

        return [
            'id' => $capture->id,
            'reference_date' => $capture->reference_date->toDateString(),
            'kind' => $capture->kind,
            'status' => $capture->status,
            'corrects_capture_id' => $capture->corrects_capture_id,
            'subject_count' => $capture->subject_count,
            'contract_count' => $capture->contract_count,
            'event_count' => $capture->event_count,
            'checksum' => $capture->checksum,
            'started_at' => $capture->started_at?->toJSON(),
            'finished_at' => $capture->finished_at?->toJSON(),
            'error' => $capture->error,
            'created_by' => $capture->created_by === null ? null : [
                'id' => $capture->created_by,
                'name' => $capture->relationLoaded('creator') ? $capture->creator?->full_name : null,
            ],
        ];
    }
}
