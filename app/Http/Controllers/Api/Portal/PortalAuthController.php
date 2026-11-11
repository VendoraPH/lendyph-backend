<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\PortalUser;
use App\Services\AuditLogService;
use App\Services\Portal\Auth\CodeDesk;
use App\Services\Portal\Auth\MemberNumber;
use App\Services\Portal\Auth\PasswordRules;
use App\Services\Portal\Auth\PortalLogin;
use App\Services\Portal\Auth\PortalSessions;
use App\Services\Portal\MemberScope;
use App\Services\Portal\PortalNotices;
use App\Services\Portal\PortalSettings;
use App\Services\Portal\PortalTerms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use OpenApi\Attributes as OA;

/**
 * Member sign-in, codes and the signed-in account (portal design §1, §3).
 * The member comes only from the token; no route takes a member id.
 */
class PortalAuthController extends Controller
{
    public const WRONG_CURRENT_PASSWORD = 'Your current password is incorrect.';

    public function __construct(
        private readonly PortalLogin $login,
        private readonly CodeDesk $codes,
        private readonly PortalSessions $sessions,
        private readonly PortalSettings $settings,
        private readonly PortalTerms $terms,
        private readonly PortalNotices $notices,
        private readonly PasswordRules $passwords,
        private readonly MemberScope $scope,
    ) {}

    #[OA\Post(
        path: '/api/portal/auth/login',
        summary: 'Member sign-in by member number and password',
        description: 'Public. 200 {token, expires_in (seconds), idle_minutes, member{first_name, member_number}, terms_required}. The token is a bearer token for the signed-in portal routes only; keep it in sessionStorage. One generic 401 for a wrong number, a wrong password, an account not yet activated and a locked account; 403 in words only after a correct password on a disabled account (`portal_access_disabled`) or an inactive member (`membership_inactive`); 503 `portal_off`. 5 wrong passwords lock the account for 15 minutes. Limited per member number (10 per 15 minutes).',
        tags: ['Member Portal'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['member_number', 'password'], properties: [
            new OA\Property(property: 'member_number', type: 'string', example: 'BRW-000123'),
            new OA\Property(property: 'password', type: 'string', format: 'password'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'Signed in'),
            new OA\Response(response: 401, description: 'The member number or password is incorrect'),
            new OA\Response(response: 403, description: 'Access turned off, or the membership is not active'),
            new OA\Response(response: 422, description: 'A field is missing'),
            new OA\Response(response: 429, description: 'Too many attempts'),
            new OA\Response(response: 503, description: 'The portal is off'),
        ],
    )]
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'member_number' => MemberNumber::rules(),
            'password' => ['required', 'string', 'max:'.PortalSettings::PASSWORD_MAX_LENGTH],
        ], MemberNumber::MESSAGES);

        return $this->login->attempt($request->input('member_number'), $request->input('password'), $request)->toResponse();
    }

    #[OA\Post(
        path: '/api/portal/auth/codes/verify',
        summary: 'Check an activation or reset code from the branch',
        description: 'Public. The member number, the 12-character code (dashes, spaces and case ignored; O reads as 0, I and L as 1) and the birthdate (YYYY-MM-DD). 200 {step_token (15 minutes), kind (activation|reset), first_name, otp_required, sent_to (masked mobile, when OTP is on), terms{version}}. Every mismatch is the same 422 (`errors.code`), whether or not the member exists; 5 wrong tries burn the code (the branch issues a new one).',
        tags: ['Member Portal'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['member_number', 'code', 'birthdate'], properties: [
            new OA\Property(property: 'member_number', type: 'string'),
            new OA\Property(property: 'code', type: 'string', example: '7KQ2-M9XA-4RTP'),
            new OA\Property(property: 'birthdate', type: 'string', format: 'date'),
        ])),
        responses: [
            new OA\Response(response: 200, description: 'The code matches'),
            new OA\Response(response: 422, description: 'The member number, code or birthdate doesn\'t match'),
            new OA\Response(response: 429, description: 'Too many attempts'),
            new OA\Response(response: 503, description: 'The portal is off'),
        ],
    )]
    public function verifyCode(Request $request): JsonResponse
    {
        $request->validate([
            'member_number' => MemberNumber::rules(),
            'code' => ['required', 'string', 'max:40'],
            'birthdate' => ['required', 'string', 'max:10'],
        ], MemberNumber::MESSAGES);

        return response()->json(['success' => true, 'data' => $this->codes->verify(
            $request->input('member_number'),
            $request->input('code'),
            $request->input('birthdate'),
        )]);
    }

    #[OA\Post(
        path: '/api/portal/auth/codes/otp',
        summary: 'Confirm the one-time password (only when OTP is on)',
        description: 'Public. 200 when it matches; 422 (`errors.otp`) when it is wrong or expired (5 minutes, 5 tries); 422 (`errors.step_token`) when the step expired.',
        tags: ['Member Portal'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['step_token', 'otp'], properties: [
            new OA\Property(property: 'step_token', type: 'string'),
            new OA\Property(property: 'otp', type: 'string', example: '123456'),
        ])),
        responses: [new OA\Response(response: 200, description: 'Confirmed'), new OA\Response(response: 422, description: 'Wrong or expired')],
    )]
    public function verifyOtp(Request $request): JsonResponse
    {
        $request->validate([
            'step_token' => ['required', 'string', 'max:128'],
            'otp' => ['required', 'string', 'max:6'],
        ]);

        $this->codes->verifyOtp($request->input('step_token'), $request->input('otp'));

        return response()->json(['success' => true, 'data' => ['verified' => true]]);
    }

    #[OA\Post(
        path: '/api/portal/auth/codes/complete',
        summary: 'Set the password and sign in',
        description: 'Public. With the step token from verify. The password: 10–128 characters, a letter and a number, not containing the member number (`errors.password`). An activation also needs `accepted_terms_version` equal to the current version (422 otherwise); `ecomm_consent` is optional. A reset clears locks and signs out every device. 200 is the same body as sign-in.',
        tags: ['Member Portal'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['step_token', 'password', 'password_confirmation'], properties: [
            new OA\Property(property: 'step_token', type: 'string'),
            new OA\Property(property: 'password', type: 'string', format: 'password'),
            new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
            new OA\Property(property: 'accepted_terms_version', type: 'string', example: '2026-11'),
            new OA\Property(property: 'ecomm_consent', type: 'boolean'),
        ])),
        responses: [new OA\Response(response: 200, description: 'Signed in'), new OA\Response(response: 422, description: 'In words, by field')],
    )]
    public function completeCode(Request $request): JsonResponse
    {
        $request->validate([
            'step_token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'max:'.PortalSettings::PASSWORD_MAX_LENGTH],
        ]);

        return $this->codes->complete($request->only(['step_token', 'password', 'password_confirmation', 'accepted_terms_version', 'ecomm_consent']), $request)->toResponse();
    }

    #[OA\Post(
        path: '/api/portal/auth/logout',
        summary: 'Sign out this device',
        description: 'Signed-in portal token. Deletes the current token.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'Signed out'), new OA\Response(response: 401, description: 'Not signed in to the portal')],
    )]
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['success' => true, 'message' => 'Signed out.']);
    }

    #[OA\Get(
        path: '/api/portal/auth/me',
        summary: 'The signed-in member (no money)',
        description: 'Signed-in portal token. Open while new terms are owed. {member{first_name, last_name, full_name, member_number, branch_name}, account{status, last_login_at, password_changed_at, terms_version, ecomm_consent}, terms_required, terms{version}, session{expires_at, idle_minutes}, features}.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        responses: [new OA\Response(response: 200, description: 'The member'), new OA\Response(response: 401, description: 'Not signed in to the portal')],
    )]
    public function me(Request $request): JsonResponse
    {
        $portalUser = $this->scope->portalUser()->load('borrower.branch:id,name');
        $member = $portalUser->borrower;
        $token = $portalUser->currentAccessToken();

        return response()->json(['success' => true, 'data' => [
            'member' => [
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'full_name' => $member->full_name,
                'member_number' => MemberNumber::of($member),
                'branch_name' => $member->branch?->name,
            ],
            'account' => [
                'status' => $portalUser->status,
                'last_login_at' => $portalUser->last_login_at,
                'password_changed_at' => $portalUser->password_changed_at,
                'terms_version' => $portalUser->terms_version,
                'ecomm_consent' => $portalUser->ecomm_consent_at !== null,
            ],
            'terms_required' => $this->terms->requiredFor($portalUser),
            'terms' => ['version' => $this->terms->current()?->version],
            'session' => [
                'expires_at' => $token instanceof PersonalAccessToken ? $token->expires_at : null,
                'idle_minutes' => $this->settings->current()->idle_minutes,
            ],
            'features' => $this->settings->features(),
        ]]);
    }

    #[OA\Post(
        path: '/api/portal/auth/terms/accept',
        summary: 'Accept the current terms',
        description: 'Signed-in portal token. `version` must be the current version (422 otherwise). Audited.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['version'], properties: [
            new OA\Property(property: 'version', type: 'string'),
            new OA\Property(property: 'ecomm_consent', type: 'boolean'),
        ])),
        responses: [new OA\Response(response: 200, description: 'Accepted'), new OA\Response(response: 422, description: 'Not the current version')],
    )]
    public function acceptTerms(Request $request): JsonResponse
    {
        $current = $this->terms->current();

        $request->validate([
            'version' => ['required', 'string', Rule::in($current === null ? [] : [$current->version])],
            'ecomm_consent' => ['sometimes', 'boolean'],
        ], ['version.in' => 'Please accept the current terms'.($current === null ? '.' : " (version {$current->version}).")]);

        $portalUser = $this->scope->portalUser();
        $changes = ['terms_version' => $current->version, 'terms_accepted_at' => now()];

        if ($request->boolean('ecomm_consent') && $portalUser->ecomm_consent_at === null) {
            $changes['ecomm_consent_at'] = now();
        }

        $before = $portalUser->terms_version;

        // Accepting the version already accepted (and owed nothing newer)
        // changes nothing and writes nothing (review L3).
        if ($before === $current->version && ! $this->terms->requiredFor($portalUser) && ! isset($changes['ecomm_consent_at'])) {
            return response()->json(['success' => true, 'data' => ['terms_version' => $current->version, 'terms_required' => false]]);
        }

        $portalUser->forceFill($changes)->save();

        AuditLogService::log(
            'portal_terms_accepted',
            $portalUser,
            ['terms_version' => $before],
            ['terms_version' => $current->version],
            "Member accepted the portal terms, version {$current->version}",
        );

        return response()->json(['success' => true, 'data' => ['terms_version' => $current->version, 'terms_required' => false]]);
    }

    #[OA\Post(
        path: '/api/portal/auth/password/change',
        summary: 'Change the password',
        description: 'Signed-in portal token. 422 (`errors.current_password`) for a wrong current password, which counts toward the lockout; a lockout also ends every session. The new password follows the sign-up rule (`errors.password`). Signs out every other device. Audited.',
        tags: ['Member Portal'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['current_password', 'password', 'password_confirmation'], properties: [
            new OA\Property(property: 'current_password', type: 'string', format: 'password'),
            new OA\Property(property: 'password', type: 'string', format: 'password'),
            new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
        ])),
        responses: [new OA\Response(response: 200, description: 'Changed'), new OA\Response(response: 422, description: 'In words, by field')],
    )]
    public function changePassword(Request $request): JsonResponse
    {
        $portalUser = $this->scope->portalUser();

        $request->validate(['current_password' => ['required', 'string', 'max:'.PortalSettings::PASSWORD_MAX_LENGTH]]);

        $correct = DB::transaction(function () use ($portalUser, $request): bool {
            $locked = PortalUser::query()->lockForUpdate()->findOrFail($portalUser->getKey());

            if (Hash::check((string) $request->input('current_password'), (string) $locked->password)) {
                return true;
            }

            $this->login->countFailure($locked, $request, MemberNumber::hash(MemberNumber::of($locked->borrower)));

            if ($locked->isTemporarilyLocked() || $locked->status === PortalUser::STATUS_LOCKED) {
                $this->sessions->revoke($locked);
            }

            return false;
        });

        if (! $correct) {
            throw ValidationException::withMessages(['current_password' => [self::WRONG_CURRENT_PASSWORD]]);
        }

        Validator::make($request->only(['password', 'password_confirmation']), [
            'password' => $this->passwords->rules($portalUser->borrower),
        ])->validate();

        if (Hash::check((string) $request->input('password'), (string) $portalUser->password)) {
            throw ValidationException::withMessages(['password' => ['Choose a password different from your current one.']]);
        }

        $current = $portalUser->currentAccessToken();

        DB::transaction(function () use ($portalUser, $request, $current): void {
            $portalUser->forceFill([
                'password' => (string) $request->input('password'),
                'password_changed_at' => now(),
                'failed_attempts' => 0,
            ])->save();

            $this->sessions->revoke($portalUser, $current instanceof PersonalAccessToken ? (int) $current->getKey() : null);

            AuditLogService::log('portal_password_changed', $portalUser, description: 'Member changed their portal password; other devices were signed out');
        });

        $this->notices->passwordChanged($portalUser);

        return response()->json(['success' => true, 'message' => 'Password changed. Your other devices were signed out.']);
    }
}
