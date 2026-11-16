<?php

namespace App\Http\Controllers\Api\Linda;

use App\Http\Controllers\Controller;
use App\Http\Requests\Linda\LindaChatRequest;
use App\Services\Linda\LindaAssistant;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * `POST /linda/chat`: one question to Linda. The route answers 501 `linda_off`
 * while the backend switch is off and is metered by the `linda` limiter (10 a
 * minute, 100 a day per user); the request needs `linda:use`. LindaAssistant
 * answers it, or throws a LindaHttpError that renders the contract's error.
 */
class LindaController extends Controller
{
    public function __construct(private readonly LindaAssistant $assistant) {}

    #[OA\Post(
        path: '/api/linda/chat',
        summary: 'Ask Linda',
        description: 'Needs linda:use. `message` (at most 500 characters) and `history` (at most 10 turns; assistant content is ignored). Answers {success: true, data: {intent, answer, blocks, links, as_of}}. Errors are {success: false, error_code, message}: 501 linda_off, 429 rate_limited, daily_limit or monthly_limit, 503 ai_unavailable or data_unavailable.',
        tags: ['Linda'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['message'], properties: [
            new OA\Property(property: 'message', type: 'string', example: 'How much did we collect this month?'),
            new OA\Property(property: 'history', type: 'array', items: new OA\Items(type: 'object')),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The reply'),
            new OA\Response(response: 403, description: 'Missing linda:use'),
            new OA\Response(response: 422, description: 'In words, by field'),
            new OA\Response(response: 429, description: 'rate_limited, daily_limit or monthly_limit'),
            new OA\Response(response: 501, description: 'linda_off'),
            new OA\Response(response: 503, description: 'ai_unavailable or data_unavailable'),
        ],
    )]
    public function chat(LindaChatRequest $request): JsonResponse
    {
        $reply = $this->assistant->ask($request->user(), $request->question(), (array) ($request->validated('history') ?? []));

        return response()->json(['success' => true, 'data' => $reply->toArray()]);
    }
}
