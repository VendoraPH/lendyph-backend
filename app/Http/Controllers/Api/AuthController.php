<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\UpdateMeRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
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
                        new OA\Property(
                            property: 'user',
                            description: 'The full user record. `user.must_change_password` is true when an '
                                .'administrator reset this password: the token below is valid, but every '
                                .'request outside GET /api/auth/me, POST /api/auth/change-password and '
                                .'POST /api/auth/logout answers 423 until the password is changed.',
                            type: 'object',
                        ),
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

        // `last_login_at` is deliberately absent from User::$fillable so it can
        // never be set from request data — a client must not be able to forge
        // its own last-login timestamp. update() therefore silently discarded
        // it and the column stayed null for every user. forceFill() bypasses
        // that guard for this one server-controlled write.
        //
        // saveQuietly() skips model events: the Auditable trait logs on
        // `updated`, so a normal save would write a second audit row on every
        // login, duplicating the explicit 'login' entry below and copying the
        // bcrypt hash into audit_logs.old_values.
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

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
        description: 'Revoke the credential this request arrived on: the access token for a bearer caller, or the session (plus a rotated CSRF token) for a session caller.',
        tags: ['Auth'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Logged out successfully'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        AuditLogService::log('logout', $user, description: "User {$user->username} logged out");

        // Revoke the credential this request arrived on — but WHICH credential
        // that is depends on how the caller authenticated.
        //
        // currentAccessToken() is a PersonalAccessToken for API-token auth or a
        // TransientToken for session-based (SPA) auth; only the former has a row
        // to delete, and delete() on the latter is a fatal Error.
        $currentToken = $user->currentAccessToken();

        if ($currentToken instanceof PersonalAccessToken) {
            $currentToken->delete();
        } else {
            // A session caller has no bearer token: the SESSION is the
            // credential, so returning "Logged out successfully." without
            // ending it would be a lie — the caller would still be signed in
            // on the next request. This is the one place where doing nothing is
            // not an acceptable answer, because logout is the request whose
            // entire purpose is to destroy the credential.
            //
            // The guards are the ones Sanctum itself consults, in its own
            // order, so this ends exactly the session that resolved this
            // request rather than a hard-coded guess at it. Only a
            // StatefulGuard has a session to end.
            foreach (Arr::wrap(config('sanctum.guard', 'web')) as $guard) {
                $guard = Auth::guard($guard);

                if ($guard instanceof StatefulGuard) {
                    $guard->logout();
                }
            }

            // API routes carry no session middleware, so there is usually no
            // session on the request to invalidate — only a stateful SPA call
            // through EnsureFrontendRequestsAreStateful has one.
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return response()->json(['message' => 'Logged out successfully.']);
    }

    #[OA\Get(
        path: '/api/auth/me',
        summary: 'Current user',
        description: 'Get the authenticated user profile with roles and permissions. Reachable even while '
            .'`data.must_change_password` is true — it is how a locked client finds out why it is locked.',
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
        description: 'Verify current password, then update to new. Revokes all other sanctum tokens as a '
            .'security precaution; the current session stays valid. Also clears `must_change_password` — this '
            .'is the only endpoint that does, and the only one besides GET /auth/me and POST /auth/logout '
            .'that a user carrying that flag may call.',
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

        // The one and only place `must_change_password` is cleared, and it is
        // cleared here because this is the one and only place the user proves
        // they chose the password themselves — `current_password` in
        // ChangePasswordRequest means the caller knew the old one too.
        //
        // Set in the same UPDATE as the password so the two cannot disagree,
        // and via forceFill() because the column is outside User::$fillable:
        // an `update()` would drop it without erroring and leave the user
        // locked out with a password they had just successfully changed.
        $user->forceFill([
            'password' => Hash::make($request->input('new_password')),
            'must_change_password' => false,
        ])->save();

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
            new OA\Response(
                response: 400,
                description: 'Nothing to refresh — the caller authenticated with a session rather than an '
                    .'API token, so there is no token to rotate. Marked by `code: no_token_to_refresh`.',
            ),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 423, description: 'Password change required — change the password first, then log in again'),
        ],
    )]
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();

        // Rotation needs something to rotate. A session-based (SPA) caller
        // holds a TransientToken, which has no row to revoke — and minting one
        // anyway would not be a refresh at all: it would silently convert a
        // cookie session into a standalone bearer credential that outlives the
        // session that produced it, including outliving logout. Unlike logout,
        // where ending the session is the caller's actual intent, there is no
        // sensible session-shaped equivalent of "rotate my token", so say so.
        //
        // 400 rather than 403: the caller is perfectly authorised, the request
        // simply does not apply to how they authenticated. `code` is the marker
        // clients branch on; the message is copy.
        if (! $currentToken instanceof PersonalAccessToken) {
            return response()->json([
                'message' => 'There is no API token on this request to refresh.',
                'code' => 'no_token_to_refresh',
            ], 400);
        }

        $currentToken->delete();

        $token = $user->createToken(
            'auth-token',
            ['*'],
            now()->addMinutes(config('auth.token_timeout', 30)),
        );

        return response()->json(['token' => $token->plainTextToken]);
    }
}
