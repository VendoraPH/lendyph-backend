<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reminders\PauseReminderRequest;
use App\Http\Requests\Reminders\ResumeReminderRequest;
use App\Http\Resources\Reminders\ReminderPauseResource;
use App\Services\Reminders\Api\ReminderPauses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Pausing and resuming reminders for everyone, a member or a loan. A pause
 * on all reminders needs `branches:view_all`.
 */
class ReminderPauseController extends Controller
{
    #[OA\Get(
        path: '/api/reminders/pauses',
        summary: 'Pauses in force (unpaginated)',
        description: 'Global pauses, and those on members and loans the caller can see.',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The pauses'),
            new OA\Response(response: 403, description: 'Missing reminders:view'),
        ],
    )]
    public function index(Request $request, ReminderPauses $pauses): JsonResponse
    {
        $this->authorize('reminders:view');

        return response()->json(['data' => ReminderPauseResource::collection($pauses->inForce($request->user()))->resolve($request)]);
    }

    #[OA\Post(
        path: '/api/reminders/pause',
        summary: 'Pause reminders for everyone, a member or a loan',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['scope', 'reason'], properties: [
            new OA\Property(property: 'scope', type: 'string', enum: ['global', 'borrower', 'loan']),
            new OA\Property(property: 'borrower_id', type: 'integer'),
            new OA\Property(property: 'loan_id', type: 'integer'),
            new OA\Property(property: 'reason', type: 'string'),
            new OA\Property(property: 'until', type: 'string', format: 'date-time', nullable: true),
        ])),
        responses: [
            new OA\Response(response: 201, description: 'The pause'),
            new OA\Response(response: 403, description: 'Missing reminders:pause, or branches:view_all for a global pause'),
            new OA\Response(response: 422, description: 'Already paused, `until` not in the future, or a member or loan outside the caller\'s branches'),
        ],
    )]
    public function pause(PauseReminderRequest $request, ReminderPauses $pauses): JsonResponse
    {
        $this->authorize('reminders:pause');
        $pause = $pauses->pause($request->user(), $request->validated());
        $pause->load(ReminderPauseResource::RELATIONS);

        return response()->json(['message' => 'Reminders paused.', 'data' => (new ReminderPauseResource($pause))->resolve($request)], 201);
    }

    #[OA\Post(
        path: '/api/reminders/resume',
        summary: 'Resume reminders for everyone, a member or a loan',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Resumed; data is null'),
            new OA\Response(response: 403, description: 'Missing reminders:pause, or branches:view_all for a global resume'),
            new OA\Response(response: 422, description: 'Nothing is paused for that target'),
        ],
    )]
    public function resume(ResumeReminderRequest $request, ReminderPauses $pauses): JsonResponse
    {
        $this->authorize('reminders:pause');
        $pauses->resume($request->user(), $request->validated());

        return response()->json(['message' => 'Reminders resumed.', 'data' => null]);
    }
}
