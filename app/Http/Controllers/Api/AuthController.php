<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\UpdateMeRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
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

    #[OA\Patch(
        path: '/api/auth/me',
        summary: 'Update current user profile',
        description: 'Self-service profile update. Only full_name, email, and mobile_number are editable. Username and role are admin-only.',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'full_name', type: 'string', example: 'Juan Dela Cruz'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, example: 'juan@example.com'),
                    new OA\Property(property: 'mobile_number', type: 'string', nullable: true, example: '09171234567'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Profile updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Validation error (e.g. email taken)'),
        ],
    )]
    public function updateMe(UpdateMeRequest $request): UserResource
    {
        $user = $request->user();
        $user->fill($request->validated())->save();

        AuditLogService::log('profile_updated', $user, description: "User {$user->username} updated their profile");

        $user->load('branch', 'roles', 'permissions');

        return new UserResource($user);
    }

    #[OA\Post(
        path: '/api/auth/change-password',
        summary: 'Change current user password',
        description: 'Verify current password, then update to new. Revokes all other sanctum tokens as a security precaution; the current session stays valid.',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['current_password', 'new_password', 'new_password_confirmation'],
                properties: [
                    new OA\Property(property: 'current_password', type: 'string', example: 'oldpassword'),
                    new OA\Property(property: 'new_password', type: 'string', minLength: 8, example: 'newpassword123'),
                    new OA\Property(property: 'new_password_confirmation', type: 'string', example: 'newpassword123'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Password updated'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 422, description: 'Current password incorrect or new password invalid'),
        ],
    )]
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['password' => Hash::make($request->input('new_password'))]);

        // Keep current session alive; invalidate all other tokens.
        // currentAccessToken() is a PersonalAccessToken for API-token auth
        // or a TransientToken for session-based (SPA) auth — only the former has an id.
        $currentToken = $request->user()->currentAccessToken();
        if ($currentToken instanceof PersonalAccessToken) {
            $user->tokens()->where('id', '!=', $currentToken->id)->delete();
        } else {
            $user->tokens()->delete();
        }

        AuditLogService::log('password_changed', $user, description: "User {$user->username} changed their password");

        return response()->json(['message' => 'Password updated successfully.']);
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
