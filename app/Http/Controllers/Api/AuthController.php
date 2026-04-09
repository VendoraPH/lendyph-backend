<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    #[OA\Post(
        path: '/api/auth/login',
        summary: 'Login',
        description: 'Authenticate with username or email and receive an access token',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['login', 'password'],
                properties: [
                    new OA\Property(property: 'login', type: 'string', example: 'admin'),
                    new OA\Property(property: 'password', type: 'string', example: 'password'),
                    new OA\Property(property: 'remember', type: 'boolean', example: false),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login successful',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'token', type: 'string'),
                        new OA\Property(property: 'user', type: 'object'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Invalid credentials'),
            new OA\Response(response: 403, description: 'Account deactivated'),
        ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('username', $request->login)
            ->orWhere('email', $request->login)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        if ($user->status === 'inactive') {
            return response()->json(['message' => 'Your account has been deactivated.'], 403);
        }

        $expiry = $request->boolean('remember')
            ? now()->addDays(30)
            : now()->addMinutes(config('auth.token_timeout', 30));

        $token = $user->createToken('auth-token', ['*'], $expiry);

        $user->update(['last_login_at' => now()]);

        AuditLogService::log('login', $user, description: "User {$user->username} logged in");

        $user->load('branch', 'roles', 'permissions');

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    #[OA\Post(
        path: '/api/auth/logout',
        summary: 'Logout',
        description: 'Revoke the current access token',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Logged out successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function logout(): JsonResponse
    {
        $user = auth()->user();

        AuditLogService::log('logout', $user, description: "User {$user->username} logged out");

        $user->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    #[OA\Get(
        path: '/api/auth/me',
        summary: 'Current user',
        description: 'Get the authenticated user profile with roles and permissions',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Current user data'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function me(): UserResource
    {
        $user = auth()->user();
        $user->load('branch', 'roles', 'permissions');

        return new UserResource($user);
    }

    #[OA\Post(
        path: '/api/auth/refresh',
        summary: 'Refresh token',
        description: 'Revoke current token and issue a new one',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'New token issued',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'token', type: 'string'),
                    ],
                ),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function refresh(): JsonResponse
    {
        $user = auth()->user();
        $user->currentAccessToken()->delete();

        $token = $user->createToken(
            'auth-token',
            ['*'],
            now()->addMinutes(config('auth.token_timeout', 30)),
        );

        return response()->json(['token' => $token->plainTextToken]);
    }
}
