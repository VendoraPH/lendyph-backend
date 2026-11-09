<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reminders\NotificationPreferencesRequest;
use App\Models\Borrower;
use App\Services\Reminders\Api\MemberPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * A member's reminder preferences. Reading them needs `borrowers:view` (and
 * the member's branch, through the binding); the mobile number and email read
 * masked. Saving needs `borrowers:update`; its answer leaves the contact
 * details out for a caller without `borrowers:view`.
 */
class ReminderPreferenceController extends Controller
{
    #[OA\Get(
        path: '/api/borrowers/{borrower}/notification-preferences',
        summary: "A member's reminder preferences",
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'borrower', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The preferences, contact masked'),
            new OA\Response(response: 403, description: 'Missing reminders:view or borrowers:view'),
            new OA\Response(response: 404, description: 'No such member in the caller\'s branches'),
        ],
    )]
    public function show(Request $request, Borrower $borrower, MemberPreferences $preferences): JsonResponse
    {
        $this->authorize('reminders:view');
        $this->authorize('borrowers:view');

        return response()->json(['data' => $preferences->present($borrower, $request)]);
    }

    #[OA\Put(
        path: '/api/borrowers/{borrower}/notification-preferences',
        summary: "Save a member's reminder switches",
        description: 'Only sms_enabled, email_enabled, preferred_channel and language are saved.',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        parameters: [new OA\Parameter(name: 'borrower', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'The preferences'),
            new OA\Response(response: 403, description: 'Missing reminders:view or borrowers:update'),
            new OA\Response(response: 404, description: 'No such member in the caller\'s branches'),
            new OA\Response(response: 422, description: 'Invalid switches'),
        ],
    )]
    public function update(NotificationPreferencesRequest $request, Borrower $borrower, MemberPreferences $preferences): JsonResponse
    {
        $this->authorize('reminders:view');
        $this->authorize('borrowers:update');
        $preferences->update($borrower, $request->validated());

        return response()->json(['message' => 'Reminder preferences saved.', 'data' => $preferences->present($borrower, $request)]);
    }
}
