<?php

namespace App\Services\MenuVisibility;

use App\Models\MenuVisibilityOverride;
use App\Models\User;
use App\Services\AuditLogService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use LogicException;
use SensitiveParameter;

/**
 * The administrator override in front of Settings → Menu Visibility (finish
 * run round 6, Kevin's task 14zfbg0e9jq).
 *
 * - The password lives nowhere in the app: the server holds only its bcrypt
 *   hash (`menu_visibility.override_password_hash`, from the deployment's
 *   MENU_VISIBILITY_OVERRIDE_PASSWORD_HASH). An empty or malformed hash opens
 *   nothing (fail closed).
 * - A right password opens the page for `menu_visibility.override_minutes`
 *   (15) on the access token it came in on: one sign-in only. Signing out
 *   deletes the token and cascades the override away; a refresh carries it to
 *   the new token with the same expiry (carry()).
 * - Every try, right or wrong, writes one audit row, never with the password.
 *   Wrong tries are counted by the `menu-visibility-override` limiter
 *   (App\Providers\MenuVisibilityServiceProvider).
 */
final class AdministratorOverride
{
    public const AUDIT_GRANTED = 'menu_visibility_override_granted';

    public const AUDIT_REFUSED = 'menu_visibility_override_refused';

    public const DEFAULT_MINUTES = 15;

    /**
     * A bcrypt hash: `$2y$` (or `$2b$`, the same algorithm, which other bcrypt
     * tools write), a two-digit cost from 04 to 31, then 53 characters of salt
     * and hash. The legacy `$2a$` and `$2x$` fail closed.
     */
    private const BCRYPT_HASH = '/^\$2[by]\$(0[4-9]|[12]\d|3[01])\$[.\/A-Za-z0-9]{53}$/';

    /** Whether this server has a usable hash, so the override can open at all. */
    public function isSetUp(): bool
    {
        return $this->hash() !== null;
    }

    /**
     * Check the password and, when it is right, open the override on this
     * sign-in's token. Audits the try either way, never with the password.
     *
     * @return CarbonInterface|null when the override now ends, or null for a wrong password
     */
    public function attempt(User $user, PersonalAccessToken $token, #[SensitiveParameter] mixed $password): ?CarbonInterface
    {
        $hash = $this->hash() ?? throw new LogicException('The administrator override has no usable hash.');

        if (! is_string($password) || $password === '' || ! Hash::driver('bcrypt')->check($password, $hash)) {
            AuditLogService::log(
                self::AUDIT_REFUSED,
                description: 'Administrator override for Menu Visibility refused: wrong password',
                userId: $user->id,
            );

            return null;
        }

        $expiresAt = now()->addMinutes(self::minutes())->startOfSecond();

        MenuVisibilityOverride::query()->where('expires_at', '<=', now())->delete();
        $this->bind($token, $user->id, $expiresAt);

        AuditLogService::log(
            self::AUDIT_GRANTED,
            newValues: ['expires_at' => $expiresAt->toDateTimeString()],
            description: 'Administrator override for Menu Visibility granted until '.$expiresAt->format('M j, Y, g:i A'),
            userId: $user->id,
        );

        return $expiresAt;
    }

    /** When the override open on this token ends, or null when none is open (or there is no token). */
    public function expiresAt(mixed $token): ?CarbonInterface
    {
        return $this->openOn($token)?->expires_at;
    }

    /** The override open on this token, read before a refresh deletes the token. */
    public function openOn(mixed $token): ?MenuVisibilityOverride
    {
        if (! $token instanceof PersonalAccessToken || $token->getKey() === null) {
            return null;
        }

        return MenuVisibilityOverride::query()->open()->where('personal_access_token_id', $token->getKey())->first();
    }

    /** End the override on this token (Lock). */
    public function end(mixed $token): void
    {
        if ($token instanceof PersonalAccessToken && $token->getKey() !== null) {
            MenuVisibilityOverride::query()->where('personal_access_token_id', $token->getKey())->delete();
        }
    }

    /**
     * Carry an override to the token a refresh issued: the same sign-in, the
     * same expiry, never longer.
     */
    public function carry(?MenuVisibilityOverride $override, PersonalAccessToken $to): void
    {
        if ($override === null || $override->expires_at->isPast()) {
            return;
        }

        $this->bind($to, $override->user_id, $override->expires_at);
    }

    /** How long a right password keeps the page open; 15 unless the deployment sets a positive number. */
    public static function minutes(): int
    {
        $minutes = (int) config('menu_visibility.override_minutes', self::DEFAULT_MINUTES);

        return $minutes > 0 ? $minutes : self::DEFAULT_MINUTES;
    }

    /**
     * One row per token, written in one statement, so a double-clicked Open
     * can't insert it twice.
     */
    private function bind(PersonalAccessToken $token, int $userId, CarbonInterface $expiresAt): void
    {
        MenuVisibilityOverride::query()->upsert(
            [['personal_access_token_id' => $token->getKey(), 'user_id' => $userId, 'expires_at' => $expiresAt->toDateTimeString()]],
            ['personal_access_token_id'],
            ['user_id', 'expires_at'],
        );
    }

    private function hash(): ?string
    {
        $hash = config('menu_visibility.override_password_hash');

        if ($hash === null || $hash === '') {
            return null;
        }

        if (! is_string($hash) || preg_match(self::BCRYPT_HASH, $hash) !== 1) {
            Log::warning('menu-visibility: MENU_VISIBILITY_OVERRIDE_PASSWORD_HASH is set but is not a bcrypt hash, so the administrator override stays closed.');

            return null;
        }

        // PHP's bcrypt reads `$2b$` as `$2y$`; Hash::check knows only `$2y$`.
        return '$2y$'.substr($hash, 4);
    }
}
