<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MenuVisibility\UpdateMenuVisibilityRequest;
use App\Services\MenuVisibility\AdministratorOverride;
use App\Services\MenuVisibility\MenuVisibility;
use App\Services\MenuVisibility\OverrideLockout;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Attributes as OA;

/**
 * Settings → Menu Visibility (finish run round 6, Kevin's task 14zfbg0e9jq):
 * which side-menu modules this deployment shows, behind
 * `menu_visibility:manage` and the administrator override password.
 */
class MenuVisibilityController extends Controller
{
    public const NOT_SET_UP = "The administrator override isn't set up on this server yet.";

    public const WRONG_PASSWORD = "That password isn't right.";

    public function __construct(
        private readonly MenuVisibility $visibility,
        private readonly AdministratorOverride $override,
        private readonly OverrideLockout $lockout,
    ) {}

    #[OA\Get(
        path: '/api/menu-visibility/hidden',
        summary: 'The hidden modules',
        description: 'Any signed-in staff user. The modules this deployment hides, submenus of a hidden menu included, in the side menu\'s order.',
        tags: ['Menu Visibility'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: '{data: {hidden: string[]}}')],
    )]
    public function hidden(): JsonResponse
    {
        return response()->json(['data' => ['hidden' => $this->visibility->hidden()]]);
    }

    #[OA\Get(
        path: '/api/menu-visibility',
        summary: 'Every module with its switch',
        description: 'Needs menu_visibility:manage and an administrator override open on this sign-in (403 `override_required` otherwise). Each module\'s `hidden` is its own switch; a submenu keeps its own while its menu is off.',
        tags: ['Menu Visibility'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: '{data: {modules: [{key, label, parent, hidden}], override_expires_at}}'),
            new OA\Response(response: 403, description: 'No permission, or `code: override_required`'),
        ],
    )]
    public function show(Request $request): JsonResponse
    {
        return $this->present(UpdateMenuVisibilityRequest::ensureOpen($request, $this->override));
    }

    #[OA\Put(
        path: '/api/menu-visibility',
        summary: 'Switch modules on and off',
        description: 'Needs menu_visibility:manage and an open administrator override. `hidden` is the full list of modules to switch off; unknown keys answer 422. One audit row when something changed, none otherwise.',
        tags: ['Menu Visibility'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['hidden'], properties: [
            new OA\Property(property: 'hidden', type: 'array', items: new OA\Items(type: 'string'), example: ['savings', 'linda']),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The same payload as GET'),
            new OA\Response(response: 403, description: 'No permission, or `code: override_required`'),
            new OA\Response(response: 422, description: 'A module Menu Visibility doesn\'t know'),
        ],
    )]
    public function update(UpdateMenuVisibilityRequest $request): JsonResponse
    {
        $this->visibility->save($request->validated('hidden'), $request->user());

        return $this->present($request->overrideExpiresAt());
    }

    #[OA\Post(
        path: '/api/menu-visibility/override',
        summary: 'Open the administrator override',
        description: 'Needs menu_visibility:manage. Checks the password against the bcrypt hash this server holds and opens Menu Visibility for 15 minutes on this sign-in only. Every try is audited, never with the password. Each try is counted, per user and per address, before its password is checked; past 5 in 15 minutes the try answers 429 unchecked. A right password gives its try back.',
        tags: ['Menu Visibility'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['password'], properties: [
            new OA\Property(property: 'password', type: 'string', format: 'password'),
        ])),
        responses: [
            new OA\Response(response: 200, description: '{data: {expires_at}}'),
            new OA\Response(response: 403, description: 'No permission'),
            new OA\Response(response: 422, description: 'A wrong password'),
            new OA\Response(response: 429, description: 'Too many wrong tries'),
            new OA\Response(response: 503, description: 'No override password is set up on this server'),
        ],
    )]
    public function grantOverride(Request $request): JsonResponse
    {
        UpdateMenuVisibilityRequest::ensureCanManage($request);

        if (! $this->override->isSetUp()) {
            return response()->json(['message' => self::NOT_SET_UP], 503);
        }

        $token = $request->user()->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return response()->json([
                'message' => 'Sign in again to use the administrator override.',
                'code' => 'no_token_for_override',
            ], 400);
        }

        // Counted before the password is checked, so tries sent at once can't
        // get past the lockout together.
        $user = $request->user();
        $ip = (string) $request->ip();
        $wait = $this->lockout->take($user, $ip);

        if ($wait !== null) {
            $this->override->refuseLocked($user);

            return response()->json([
                'message' => OverrideLockout::lockedMessage($wait),
                'retry_after' => $wait,
            ], 429, ['Retry-After' => (string) $wait]);
        }

        $expiresAt = $this->override->attempt($user, $token, $request->input('password'));

        if ($expiresAt === null) {
            return response()->json([
                'message' => self::WRONG_PASSWORD,
                'errors' => ['password' => [self::WRONG_PASSWORD]],
            ], 422);
        }

        $this->lockout->giveBack($user, $ip);

        return response()->json(['data' => ['expires_at' => $expiresAt->toIso8601String()]]);
    }

    #[OA\Delete(
        path: '/api/menu-visibility/override',
        summary: 'Lock: end this sign-in\'s administrator override',
        tags: ['Menu Visibility'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 204, description: 'Ended'), new OA\Response(response: 403, description: 'No permission')],
    )]
    public function endOverride(Request $request): Response
    {
        UpdateMenuVisibilityRequest::ensureCanManage($request);

        $this->override->end($request->user()->currentAccessToken());

        return response()->noContent();
    }

    private function present(?CarbonInterface $overrideExpiresAt): JsonResponse
    {
        return response()->json(['data' => [
            'modules' => $this->visibility->modules(),
            'override_expires_at' => $overrideExpiresAt?->toIso8601String(),
        ]]);
    }
}
