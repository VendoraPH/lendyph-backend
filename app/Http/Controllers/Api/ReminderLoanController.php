<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Loan;
use App\Services\Reminders\Api\LoanReminderSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ReminderLoanController extends Controller
{
    #[OA\Get(
        path: '/api/loans/{loan}/reminders',
        summary: "A loan's reminders: next due, next reminder, pauses, suggested template and timeline",
        description: '`next_amount_due` is what the loan engine says is due by the next open period (RepaymentService::amountDueNow). A message that was only logged reads "logged in test mode", never "sent".',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'loan', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'loan_id, next_due_date, next_amount_due, next_reminder_at, pause, inherited_pause, suggested_template_type, events'),
            new OA\Response(response: 403, description: 'Missing reminders:view'),
            new OA\Response(response: 404, description: 'No such loan in the caller\'s branches'),
        ],
    )]
    public function show(Request $request, Loan $loan, LoanReminderSummary $summary): JsonResponse
    {
        $this->authorize('reminders:view');

        return response()->json(['data' => $summary->build($loan, $request)]);
    }
}
