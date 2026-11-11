<?php

namespace App\Services\Portal\Auth;

use App\Models\Borrower;
use App\Models\PortalLoginEvent;
use App\Models\PortalUser;
use App\Services\AuditLogService;
use App\Services\Portal\PortalNotices;
use App\Services\Portal\PortalSettings;
use App\Services\Portal\PortalTerms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Member sign-in by member number and password (portal design §1, §3).
 *
 * - Every refusal that could tell an account's existence or state apart is
 *   the same 401 (FAILED): an unknown number, no password yet, a wrong
 *   password, and a locked account.
 * - While an account is locked the password is not even checked, so a
 *   lockout can't confirm a password.
 * - Only a correct password on an account that isn't locked, when the account
 *   is disabled or the member inactive, gets a 403 in words. A wrong password
 *   on a disabled account counts toward the lockout like any other.
 * - Every path runs exactly one bcrypt check, so timing reveals nothing.
 * - 5 failures in a row lock it for 15 minutes; 3 lockouts in 24 hours lock
 *   it until staff unlock it (both settings).
 * - Every attempt is a `portal_login_events` row, not an audit row; a success
 *   is also audited as the member.
 */
final class PortalLogin
{
    public const FAILED = 'The member number or password is incorrect.';

    public const DISABLED = 'Your portal access is turned off. Please contact your branch.';

    public const MEMBER_INACTIVE = 'Your membership isn\'t active, so you can\'t sign in to the portal right now. Please contact your branch.';

    public const PORTAL_OFF = 'The member portal is not available right now.';

    private const DUMMY_HASH_KEY = 'portal:auth:dummy-hash';

    public function __construct(
        private readonly PortalSettings $settings,
        private readonly PortalSessions $sessions,
        private readonly PortalTerms $terms,
        private readonly PortalNotices $notices,
    ) {}

    public function attempt(mixed $memberNumber, mixed $password, Request $request): PortalAnswer
    {
        $password = is_string($password) ? $password : '';
        $loginHash = MemberNumber::hash($memberNumber);
        $member = MemberNumber::find($memberNumber);

        if (! $this->settings->enabled()) {
            $this->record($request, $loginHash, $member === null ? null : PortalUser::query()->where('borrower_id', $member->getKey())->value('id'), PortalLoginEvent::OUTCOME_PORTAL_OFF);

            return PortalAnswer::refused(503, self::PORTAL_OFF, 'portal_off');
        }

        $result = DB::transaction(function () use ($member, $password, $request, $loginHash): array {
            $portalUser = $member === null ? null : PortalUser::query()->where('borrower_id', $member->getKey())->lockForUpdate()->first();

            // Exactly one bcrypt check on every path (review M1), so the time an
            // answer takes tells nothing about the account. A missing or
            // locked account is checked against the stand-in hash; a locked
            // one never against its own, so a lockout can't confirm a password.
            if ($portalUser === null || $portalUser->password === null) {
                Hash::check($password, self::dummyHash());
                $this->record($request, $loginHash, $portalUser?->getKey(), PortalLoginEvent::OUTCOME_FAILED);

                return [self::failed(), null, null];
            }

            if ($portalUser->isHardLocked() || $portalUser->isTemporarilyLocked()) {
                Hash::check($password, self::dummyHash());
                $this->record($request, $loginHash, $portalUser->getKey(), PortalLoginEvent::OUTCOME_REFUSED_LOCKED);

                return [self::failed(), null, null];
            }

            // A wrong password counts toward the lockout whatever the
            // account's state, a disabled one included (review M2).
            if (! Hash::check($password, $portalUser->password)) {
                $this->countFailure($portalUser, $request, $loginHash);

                return [self::failed(), null, null];
            }

            if ($portalUser->status === PortalUser::STATUS_DISABLED) {
                $this->record($request, $loginHash, $portalUser->getKey(), PortalLoginEvent::OUTCOME_DISABLED);

                return [PortalAnswer::refused(403, self::DISABLED, 'portal_access_disabled'), null, null];
            }

            /** @var Borrower $member */
            if ($member->status !== 'active') {
                $this->record($request, $loginHash, $portalUser->getKey(), PortalLoginEvent::OUTCOME_DISABLED);

                return [PortalAnswer::refused(403, self::MEMBER_INACTIVE, 'membership_inactive'), null, null];
            }

            return $this->signIn($portalUser, $request, $loginHash);
        });

        [$answer, $portalUser, $device] = $result;

        if ($portalUser !== null) {
            $this->notices->newSignIn($portalUser, $device);
        }

        return $answer;
    }

    /**
     * Sign a member in: reset the failure count, issue the token and record
     * the sign-in. Used by a password sign-in and by a completed code.
     *
     * @return array{0: PortalAnswer, 1: PortalUser, 2: string}
     */
    public function signIn(PortalUser $portalUser, Request $request, ?string $loginHash = null): array
    {
        $portalUser->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();

        $token = $this->sessions->issue($portalUser, $request);
        $device = (string) $token->accessToken->name;

        $this->record($request, $loginHash ?? MemberNumber::hash(MemberNumber::of($portalUser->borrower)), $portalUser->getKey(), PortalLoginEvent::OUTCOME_SUCCESS, (int) $token->accessToken->getKey());

        AuditLogService::log(
            'portal_signed_in',
            $portalUser,
            description: sprintf('Member signed in to the portal on %s', $device),
            member: $portalUser,
        );

        return [PortalAnswer::ok($this->sessions->loginBody($portalUser, $token, $this->terms->requiredFor($portalUser))), $portalUser, $device];
    }

    /**
     * A wrong password on an active account. At the limit the account is
     * locked for `lockout_minutes`; the `hard_lock_after`-th lockout in 24
     * hours locks it until staff unlock it, and ends its sessions.
     */
    public function countFailure(PortalUser $portalUser, Request $request, string $loginHash): void
    {
        $settings = $this->settings->current();
        $failures = $portalUser->failed_attempts + 1;

        if ($failures < $settings->max_login_attempts) {
            $portalUser->forceFill(['failed_attempts' => $failures])->save();
            $this->record($request, $loginHash, $portalUser->getKey(), PortalLoginEvent::OUTCOME_FAILED);

            return;
        }

        $portalUser->forceFill(['failed_attempts' => 0, 'locked_until' => now()->addMinutes($settings->lockout_minutes)])->save();
        $this->record($request, $loginHash, $portalUser->getKey(), PortalLoginEvent::OUTCOME_LOCKED_OUT);

        AuditLogService::log(
            'portal_locked_out',
            $portalUser,
            null,
            ['locked_for_minutes' => $settings->lockout_minutes],
            sprintf('Member portal account locked for %d minutes after %d wrong passwords', $settings->lockout_minutes, $settings->max_login_attempts),
            asSystem: true,
        );

        // Only lockouts since the last staff unlock or code reset count
        // (review L5).
        $since = now()->subDay();
        if ($portalUser->lockouts_cleared_at !== null && $portalUser->lockouts_cleared_at->gt($since)) {
            $since = $portalUser->lockouts_cleared_at;
        }

        $lockouts = PortalLoginEvent::query()
            ->where('portal_user_id', $portalUser->getKey())
            ->where('outcome', PortalLoginEvent::OUTCOME_LOCKED_OUT)
            ->where('created_at', '>=', $since)
            ->count();

        // Whatever the account's status (re-check M2): a disabled account
        // keeps its status and reason and is held by `hard_locked_at`, so its
        // password can't be guessed fifteen minutes at a time.
        if ($lockouts >= $settings->hard_lock_after && $portalUser->hard_locked_at === null) {
            $before = $portalUser->status;
            $portalUser->forceFill([
                'status' => $before === PortalUser::STATUS_ACTIVE ? PortalUser::STATUS_LOCKED : $before,
                'hard_locked_at' => now(),
            ])->save();
            $this->sessions->revoke($portalUser);

            AuditLogService::log(
                'portal_hard_locked',
                $portalUser,
                ['status' => $before],
                ['status' => $portalUser->status, 'hard_locked' => true],
                sprintf('Member portal account locked until staff unlock it, after %d lockouts in 24 hours', $lockouts),
                asSystem: true,
            );
        }
    }

    public function record(Request $request, string $loginHash, ?int $portalUserId, string $outcome, ?int $tokenId = null): void
    {
        PortalLoginEvent::query()->create([
            'portal_user_id' => $portalUserId,
            'login_hash' => $loginHash,
            'outcome' => $outcome,
            'token_id' => $tokenId,
            'device' => PortalSessions::device($request->userAgent()),
            'user_agent' => PortalSessions::cleanUserAgent($request->userAgent()),
            'ip_address' => $request->ip(),
        ]);
    }

    public static function failed(): PortalAnswer
    {
        return PortalAnswer::refused(401, self::FAILED);
    }

    /**
     * The stand-in hash checked when there is no account (or it is locked), so
     * those answers cost the same one bcrypt check as a wrong password.
     *
     * Stored with the portal settings (`auth_dummy_hash`), so it never
     * depends on the cache store keeping anything between requests; the cache
     * only saves the read. Built once at the configured cost and rebuilt only
     * when that cost changes (Hash::needsRehash).
     */
    public static function dummyHash(): string
    {
        $cached = Cache::get(self::DUMMY_HASH_KEY);

        if (is_string($cached) && ! Hash::needsRehash($cached)) {
            return $cached;
        }

        $stored = DB::table('portal_settings')->orderBy('id')->value('auth_dummy_hash');

        if (! is_string($stored) || $stored === '' || Hash::needsRehash($stored)) {
            $stored = Hash::make(Str::random(40));
            DB::table('portal_settings')->orderBy('id')->limit(1)->update(['auth_dummy_hash' => $stored]);
        }

        Cache::forever(self::DUMMY_HASH_KEY, $stored);

        return $stored;
    }
}
