<?php

namespace App\Services\Portal\Auth;

use App\Models\Borrower;
use App\Models\PortalCode;
use App\Models\PortalOtpChallenge;
use App\Models\PortalUser;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Portal\PortalAccess;
use App\Services\Portal\PortalNotices;
use App\Services\Portal\PortalSettings;
use App\Services\Portal\PortalTerms;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff-issued activation and reset codes (portal design §1, "Accounts and the
 * activation code"; Arvin, 2026-11-10).
 *
 * Issuing (staff): after ticking that they checked the member's valid ID. One
 * open code per member (a new one supersedes it); at most 5 per member a day
 * and 30 per staff user an hour. The code is returned in the 201 body only,
 * once; only its hash is stored, and no log or audit row holds it.
 *
 * Redeeming (member, public): member number + code + birthdate. 5 wrong tries
 * burn the code. Every refusal is the same 422 (CODE_FAILED), whether or not
 * the member exists. A match hands out a 15-minute step token, then (only when
 * OTP is on) a one-time password, then complete sets the password and signs
 * the member in.
 */
final class CodeDesk
{
    public const CODE_FAILED = 'The member number, code or birthdate doesn\'t match.';

    public const STEP_EXPIRED = 'This step has expired. Enter your code again.';

    public const OTP_FAILED = 'The one-time password is wrong or has expired.';

    public const OTP_NEEDED = 'Enter the one-time password sent to your mobile number first.';

    public const NO_TERMS = 'The portal terms aren\'t published yet. Please contact your branch.';

    public const STEP_MINUTES = 15;

    /**
     * The valid ID types a staff member may say they checked: the same keys
     * and words members' valid IDs use (the frontend's VALID_ID_OPTIONS).
     */
    public const IDENTITY_TYPES = [
        'philippine_id' => 'Philippine National ID (PhilSys)',
        'drivers_license' => 'Driver\'s License',
        'passport' => 'Passport',
        'sss' => 'SSS ID',
        'umid' => 'UMID',
        'voters_id' => 'Voter\'s ID',
        'postal_id' => 'Postal ID',
        'prc_id' => 'PRC ID',
        'tin_id' => 'TIN ID',
        'others' => 'Others',
    ];

    public const CODES_PER_MEMBER_PER_DAY = 5;

    public const CODES_PER_STAFF_PER_HOUR = 30;

    public function __construct(
        private readonly PortalSettings $settings,
        private readonly PortalTerms $terms,
        private readonly PortalAccess $access,
        private readonly PortalLogin $login,
        private readonly PasswordRules $passwords,
        private readonly PortalNotices $notices,
        private readonly OtpSender $otp,
    ) {}

    /**
     * Issue a code. `invite` gives an activation code to a member who isn't
     * active on the portal yet; `reset` a password reset code to an active or
     * locked account.
     *
     * @return array{code: string, kind: string, expires_at: CarbonInterface, slip: array<string, mixed>}
     */
    public function issue(Borrower $member, string $kind, string $identityCheckedAs, User $staff): array
    {
        return DB::transaction(function () use ($member, $kind, $identityCheckedAs, $staff): array {
            $member = Borrower::query()->lockForUpdate()->findOrFail($member->getKey());
            $portalUser = PortalUser::query()->where('borrower_id', $member->getKey())->lockForUpdate()->first();

            $this->assertCanIssue($member, $portalUser, $kind);
            $this->assertUnderCaps($portalUser, $staff);

            if ($portalUser === null) {
                $portalUser = PortalUser::query()->create([
                    'borrower_id' => $member->getKey(),
                    'status' => PortalUser::STATUS_INVITED,
                    'invited_by' => $staff->getKey(),
                    'invited_at' => now(),
                ]);
            } elseif ($kind === PortalCode::KIND_ACTIVATION) {
                $portalUser->forceFill(['invited_by' => $staff->getKey(), 'invited_at' => now()])->save();
            }

            $this->access->closeOpenCodes($portalUser);

            $settings = $this->settings->current();
            $hours = $kind === PortalCode::KIND_ACTIVATION ? $settings->activation_code_hours : $settings->reset_code_hours;
            $plain = AccessCode::generate();
            $expiresAt = now()->addHours($hours);

            PortalCode::query()->create([
                'portal_user_id' => $portalUser->getKey(),
                'kind' => $kind,
                'code_hash' => Hash::make($plain),
                'expires_at' => $expiresAt,
                'status' => PortalCode::STATUS_OPEN,
                'live_key' => $portalUser->getKey(),
                'identity_checked_as' => $identityCheckedAs,
                'issued_by' => $staff->getKey(),
            ]);

            AuditLogService::log(
                'portal_code_issued',
                $portalUser,
                null,
                ['kind' => $kind, 'expires_at' => $expiresAt->toIso8601String(), 'identity_checked_as' => $identityCheckedAs],
                sprintf(
                    '%s code issued for %s, valid until %s (valid ID checked: %s)',
                    $kind === PortalCode::KIND_ACTIVATION ? 'Portal activation' : 'Portal password reset',
                    $this->access->memberLabel($portalUser),
                    $expiresAt->copy()->timezone('Asia/Manila')->format('j M Y, g:i A'),
                    self::IDENTITY_TYPES[$identityCheckedAs] ?? $identityCheckedAs,
                ),
                (int) $staff->getKey(),
            );

            return [
                'code' => AccessCode::format($plain),
                'kind' => $kind,
                'expires_at' => $expiresAt,
                'slip' => $this->slip($member, $kind, AccessCode::format($plain), $expiresAt),
            ];
        });
    }

    /** @return array{step_token: string, kind: string, first_name: string, otp_required: bool, sent_to: ?string, terms: array{version: ?string}} */
    public function verify(mixed $memberNumber, mixed $code, mixed $birthdate): array
    {
        $member = MemberNumber::find($memberNumber);
        $normalised = AccessCode::normalise($code);

        $outcome = DB::transaction(function () use ($member, $normalised, $birthdate): ?array {
            $portalUser = $member === null ? null : PortalUser::query()->where('borrower_id', $member->getKey())->lockForUpdate()->first();
            $open = $portalUser === null ? null : PortalCode::query()
                ->where('portal_user_id', $portalUser->getKey())
                ->where('status', PortalCode::STATUS_OPEN)
                ->lockForUpdate()
                ->first();

            if ($open === null) {
                Hash::check($normalised, PortalLogin::dummyHash());

                return null;
            }

            // One bcrypt check on every path, expired included (review M1).
            if ($open->expires_at->isPast()) {
                Hash::check($normalised, PortalLogin::dummyHash());
                $open->forceFill(['status' => PortalCode::STATUS_EXPIRED, 'live_key' => null, 'step_hash' => null, 'step_expires_at' => null])->save();

                return null;
            }

            $codeMatches = Hash::check($normalised, $open->code_hash) && $normalised !== '';
            $birthdateMatches = $this->birthdateMatches($member, $birthdate);

            if (! $codeMatches || ! $birthdateMatches) {
                $attempts = $open->attempts + 1;
                $open->forceFill($attempts >= PortalCode::MAX_ATTEMPTS
                    ? ['attempts' => $attempts, 'status' => PortalCode::STATUS_BURNED, 'live_key' => null, 'step_hash' => null, 'step_expires_at' => null]
                    : ['attempts' => $attempts])->save();

                return null;
            }

            if (! $this->kindFits($open->kind, $portalUser, $member)) {
                return null;
            }

            $step = Str::random(64);
            $open->forceFill([
                'step_hash' => hash('sha256', $step),
                'step_expires_at' => min(now()->addMinutes(self::STEP_MINUTES), $open->expires_at),
            ])->save();

            return [$portalUser, $open, $step];
        });

        if ($outcome === null) {
            throw self::refusal('code', self::CODE_FAILED);
        }

        [$portalUser, $open, $step] = $outcome;
        $otpRequired = $this->settings->current()->otp_required;

        return [
            'step_token' => $step,
            'kind' => $open->kind,
            'first_name' => (string) $member->first_name,
            'otp_required' => $otpRequired,
            'sent_to' => $otpRequired ? $this->otp->challenge($portalUser, $open, hash('sha256', $step)) : null,
            'terms' => ['version' => $this->terms->current()?->version],
        ];
    }

    public function verifyOtp(mixed $stepToken, mixed $otp): void
    {
        $code = $this->openStep($stepToken);

        $verified = DB::transaction(function () use ($code, $otp): bool {
            $challenge = PortalOtpChallenge::query()
                ->where('portal_code_id', $code->getKey())
                ->where('step_hash', $code->step_hash)
                ->whereNull('verified_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($challenge === null || $challenge->expires_at->isPast() || $challenge->attempts >= PortalOtpChallenge::MAX_ATTEMPTS) {
                return false;
            }

            if (! is_string($otp) || ! Hash::check($otp, $challenge->code_hash)) {
                $challenge->forceFill(['attempts' => $challenge->attempts + 1])->save();

                return false;
            }

            $challenge->forceFill(['verified_at' => now()])->save();

            return true;
        });

        if (! $verified) {
            throw self::refusal('otp', self::OTP_FAILED);
        }
    }

    /**
     * Set the password, record the terms and consent, close the code and
     * sign the member in. A reset clears locks and revokes every token.
     *
     * @param  array<string, mixed>  $input
     */
    public function complete(array $input, Request $request): PortalAnswer
    {
        $code = $this->openStep($input['step_token'] ?? null);
        $portalUser = $code->portalUser;
        $member = $portalUser->borrower;

        $otpPassed = PortalOtpChallenge::query()
            ->where('portal_code_id', $code->getKey())
            ->where('step_hash', $code->step_hash)
            ->whereNotNull('verified_at')
            ->exists();

        if ($this->settings->current()->otp_required && ! $otpPassed) {
            throw self::refusal('otp', self::OTP_NEEDED);
        }

        $current = $this->terms->current();
        $isActivation = $code->kind === PortalCode::KIND_ACTIVATION;

        if ($isActivation && $current === null) {
            throw self::refusal('accepted_terms_version', self::NO_TERMS);
        }

        Validator::make($input, [
            'password' => $this->passwords->rules($member),
            'accepted_terms_version' => $isActivation ? ['required', 'string', Rule::in([$current->version])] : ['nullable', 'string'],
            'ecomm_consent' => ['sometimes', 'boolean'],
        ], [
            'accepted_terms_version.required' => 'Please accept the terms of use and the privacy notice.',
            'accepted_terms_version.in' => 'Please accept the current terms (version '.($current?->version ?? '').').',
        ])->validate();

        [$answer, $signedIn] = DB::transaction(function () use ($code, $portalUser, $member, $input, $isActivation, $current, $request): array {
            $code = PortalCode::query()->lockForUpdate()->findOrFail($code->getKey());
            $portalUser = PortalUser::query()->lockForUpdate()->findOrFail($portalUser->getKey());

            if ($code->status !== PortalCode::STATUS_OPEN || $code->step_expires_at === null || $code->step_expires_at->isPast()
                || ! $this->kindFits($code->kind, $portalUser, $member->fresh())) {
                throw self::refusal('step_token', self::STEP_EXPIRED);
            }

            $code->forceFill([
                'status' => PortalCode::STATUS_USED,
                'used_at' => now(),
                'live_key' => null,
                'step_hash' => null,
                'step_expires_at' => null,
            ])->save();

            $changes = [
                'password' => (string) $input['password'],
                'status' => PortalUser::STATUS_ACTIVE,
                'password_changed_at' => now(),
                'failed_attempts' => 0,
                'locked_until' => null,
                'hard_locked_at' => null,
                'lockouts_cleared_at' => now(),
            ];

            if ($isActivation) {
                $changes['activated_at'] = now();
                $changes['terms_version'] = $current->version;
                $changes['terms_accepted_at'] = now();
            }

            if (filter_var($input['ecomm_consent'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $changes['ecomm_consent_at'] = $portalUser->ecomm_consent_at ?? now();
            }

            $before = $portalUser->status;
            $portalUser->forceFill($changes)->save();

            if (! $isActivation) {
                $portalUser->tokens()->delete();
            }

            AuditLogService::log(
                $isActivation ? 'portal_activated' : 'portal_password_reset',
                $portalUser,
                ['status' => $before],
                array_filter([
                    'status' => PortalUser::STATUS_ACTIVE,
                    'terms_version' => $isActivation ? $current->version : null,
                    'ecomm_consent' => isset($changes['ecomm_consent_at']) ? true : null,
                ], fn ($value) => $value !== null),
                $isActivation ? 'Member activated their portal account with a code from the branch' : 'Member reset their portal password with a code from the branch',
                member: $portalUser,
            );

            [$answer, $signedIn] = $this->login->signIn($portalUser, $request);

            return [$answer, $signedIn];
        });

        if (! $isActivation) {
            $this->notices->passwordReset($signedIn);
        }

        return $answer;
    }

    /** The open code a live step token belongs to, or the generic 422. */
    private function openStep(mixed $stepToken): PortalCode
    {
        $code = is_string($stepToken) && $stepToken !== '' ? PortalCode::query()
            ->where('step_hash', hash('sha256', $stepToken))
            ->where('status', PortalCode::STATUS_OPEN)
            ->first() : null;

        if ($code === null || $code->step_expires_at === null || $code->step_expires_at->isPast() || $code->expires_at->isPast()) {
            throw self::refusal('step_token', self::STEP_EXPIRED);
        }

        return $code;
    }

    /**
     * An activation code fits an invited account, a reset code an active or
     * locked one, and either needs an active member.
     */
    private function kindFits(string $kind, PortalUser $portalUser, ?Borrower $member): bool
    {
        if ($member === null || $member->status !== 'active') {
            return false;
        }

        return $kind === PortalCode::KIND_ACTIVATION
            ? $portalUser->status === PortalUser::STATUS_INVITED
            : in_array($portalUser->status, [PortalUser::STATUS_ACTIVE, PortalUser::STATUS_LOCKED], true);
    }

    private function birthdateMatches(?Borrower $member, mixed $birthdate): bool
    {
        if ($member?->birthdate === null || ! is_string($birthdate) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate) !== 1) {
            return false;
        }

        return Carbon::parse($member->birthdate)->toDateString() === $birthdate;
    }

    private function assertCanIssue(Borrower $member, ?PortalUser $portalUser, string $kind): void
    {
        if ($kind === PortalCode::KIND_ACTIVATION) {
            if ($member->status !== 'active') {
                throw ValidationException::withMessages(['member' => ['Only an active member can be invited to the portal.']]);
            }

            if ($member->birthdate === null) {
                throw ValidationException::withMessages(['member' => ['Add the member\'s birthdate first: members confirm it when they use their code.']]);
            }

            if ($this->terms->current() === null) {
                throw ValidationException::withMessages(['member' => ['Publish the portal terms first (Settings → Member Portal).']]);
            }

            if ($portalUser !== null && in_array($portalUser->status, [PortalUser::STATUS_ACTIVE, PortalUser::STATUS_LOCKED], true)) {
                throw new HttpResponseException(response()->json([
                    'success' => false,
                    'message' => 'This member already uses the portal. Issue a password reset code instead.',
                ], 409));
            }

            if ($portalUser?->status === PortalUser::STATUS_DISABLED) {
                throw ValidationException::withMessages(['member' => ['Portal access is turned off for this member. Turn it back on first.']]);
            }

            return;
        }

        if ($portalUser === null || $portalUser->status === PortalUser::STATUS_INVITED) {
            throw ValidationException::withMessages(['member' => ['This member hasn\'t activated the portal yet. Issue a new activation code instead.']]);
        }

        if ($portalUser->status === PortalUser::STATUS_DISABLED) {
            throw ValidationException::withMessages(['member' => ['Portal access is turned off for this member. Turn it back on first.']]);
        }

        if ($member->status !== 'active') {
            throw ValidationException::withMessages(['member' => ['Only an active member can be given a reset code.']]);
        }
    }

    private function assertUnderCaps(?PortalUser $portalUser, User $staff): void
    {
        if ($portalUser !== null && PortalCode::query()
            ->where('portal_user_id', $portalUser->getKey())
            ->where('created_at', '>=', now()->subDay())
            ->count() >= self::CODES_PER_MEMBER_PER_DAY) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'This member was given '.self::CODES_PER_MEMBER_PER_DAY.' codes in the last 24 hours. Try again tomorrow.',
            ], 429));
        }

        if (PortalCode::query()
            ->where('issued_by', $staff->getKey())
            ->where('created_at', '>=', now()->subHour())
            ->count() >= self::CODES_PER_STAFF_PER_HOUR) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'You issued '.self::CODES_PER_STAFF_PER_HOUR.' codes in the last hour. Try again later.',
            ], 429));
        }
    }

    /** @return array<string, mixed> */
    private function slip(Borrower $member, string $kind, string $code, CarbonInterface $expiresAt): array
    {
        $manila = $expiresAt->copy()->timezone('Asia/Manila');

        return [
            'title' => $kind === PortalCode::KIND_ACTIVATION ? 'Member Portal activation code' : 'Member Portal password reset code',
            'member_name' => $member->full_name,
            'member_number' => MemberNumber::of($member),
            'code' => $code,
            'expires_at' => $expiresAt,
            'expires_at_text' => $manila->format('j M Y, g:i A').' (Philippine time)',
            'portal_path' => '/member',
            'instructions' => [
                'Open the member portal and choose "I have a code from my branch".',
                'Enter your member number, this code and your birthdate.',
                'Choose a password. Never share this code or your password with anyone, including staff.',
            ],
        ];
    }

    private static function refusal(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => [$message]]);
    }
}
