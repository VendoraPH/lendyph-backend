<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reminders\ManualReminderRequest;
use App\Http\Resources\Reminders\ReminderQueueItemResource;
use App\Models\Loan;
use App\Services\Reminders\Api\ManualReminders;
use App\Services\Reminders\Api\ReminderLists;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * Sending a reminder by hand. It only queues; `reminders:dispatch` hands the
 * rows to the driver (log-only) after its own final check.
 */
class ReminderManualSendController extends Controller
{
    #[OA\Post(
        path: '/api/reminders/manual-send/preview',
        summary: 'What a manual reminder would say, and what blocks it',
        description: 'Runs the engine\'s final check on an unsaved reminder per channel. `blocked_reasons` lists every reason it can\'t go; `notes` says when it would go if contact hours haven\'t opened yet. Writes nothing.',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['loan_id', 'channel', 'template_type'], properties: [
            new OA\Property(property: 'loan_id', type: 'integer'),
            new OA\Property(property: 'channel', type: 'string', enum: ['sms', 'email', 'both']),
            new OA\Property(property: 'template_type', type: 'string'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'borrower_name, loan_account_number, installment_number, due_date, amount_due, messages, blocked_reasons, notes, test_mode'),
            new OA\Response(response: 403, description: 'Missing reminders:send'),
            new OA\Response(response: 422, description: 'Invalid request, or a loan outside the caller\'s branches'),
        ],
    )]
    public function preview(ManualReminderRequest $request, ManualReminders $manual): JsonResponse
    {
        $this->authorize('reminders:send');
        $loan = Loan::query()->with(['borrower', 'branch', 'amortizationSchedules'])->findOrFail((int) $request->validated('loan_id'));

        return response()->json(['data' => $manual->preview($loan, (string) $request->validated('channel'), (string) $request->validated('template_type'))]);
    }

    #[OA\Post(
        path: '/api/reminders/manual-send',
        summary: 'Queue a manual reminder',
        description: 'Repeats the preview\'s checks under the loan\'s lock. 422 on `channel` while anything blocks it, including a manual reminder already queued for that channel today. Writes one reminder_manual_send audit row on the loan.',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 201, description: 'queued: the queued reminders'),
            new OA\Response(response: 403, description: 'Missing reminders:send'),
            new OA\Response(response: 409, description: 'Another change to the loan was saved at the same time'),
            new OA\Response(response: 422, description: 'Blocked, invalid, or a loan outside the caller\'s branches'),
        ],
    )]
    public function send(ManualReminderRequest $request, ManualReminders $manual): JsonResponse
    {
        $this->authorize('reminders:send');
        $queued = $manual->send((int) $request->validated('loan_id'), (string) $request->validated('channel'), (string) $request->validated('template_type'), $request->user());
        $queued->load(ReminderLists::QUEUE_RELATIONS);

        return response()->json([
            'message' => 'Reminder queued.',
            'data' => ['queued' => ReminderQueueItemResource::collection($queued)->resolve($request)],
        ], 201);
    }
}
