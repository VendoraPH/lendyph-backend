<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reminders\UpdateReminderSettingsRequest;
use App\Services\Reminders\Api\ReminderSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * The reminders settings. Readable with `reminders:view` (credentials are
 * write-only and never returned); saving needs `reminders:settings`.
 */
class ReminderSettingController extends Controller
{
    #[OA\Get(
        path: '/api/reminders/settings',
        summary: 'Reminder settings, with the deployment\'s read-only test_mode',
        description: '`test_mode` is true unless live sending is on and both drivers reach a real provider. Credentials read only as has_credentials. `branches` lists the caller\'s branches.',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The settings'),
            new OA\Response(response: 403, description: 'Missing reminders:view'),
        ],
    )]
    public function show(Request $request, ReminderSettingsService $settings): JsonResponse
    {
        $this->authorize('reminders:view');

        return response()->json(['data' => $settings->present($request->user())]);
    }

    #[OA\Put(
        path: '/api/reminders/settings',
        summary: 'Save reminder settings',
        description: 'A blank or absent api_key or api_secret keeps the stored one. test_mode is ignored: a saved provider never changes the driver, so sending stays log-only.',
        tags: ['Reminders'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'The settings'),
            new OA\Response(response: 403, description: 'Missing reminders:settings, or a branch outside the caller\'s branches'),
            new OA\Response(response: 422, description: 'Outside the engine\'s limits'),
        ],
    )]
    public function update(UpdateReminderSettingsRequest $request, ReminderSettingsService $settings): JsonResponse
    {
        $this->authorize('reminders:settings');
        $settings->update($request->user(), $request->validated());

        return response()->json(['message' => 'Reminder settings saved.', 'data' => $settings->present($request->user())]);
    }
}
