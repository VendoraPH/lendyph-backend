<?php

namespace App\Services\Portal\Auth;

use App\Models\PortalLoginEvent;
use App\Models\PortalUser;
use App\Services\AuditLogService;
use App\Services\Portal\PortalSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Portal sessions (portal design §1): a Sanctum bearer token with the one
 * ability `portal`, expiring `session_minutes` after issue, with no refresh.
 * The token's name is the device it was issued to, which the sessions list
 * shows; its sign-in event (joined on `token_id`) gives the address.
 */
final class PortalSessions
{
    public function __construct(private readonly PortalSettings $settings) {}

    public function issue(PortalUser $portalUser, Request $request): NewAccessToken
    {
        return $portalUser->createToken(
            self::device($request->userAgent()),
            [PortalUser::ABILITY],
            now()->addMinutes($this->settings->current()->session_minutes),
        );
    }

    /**
     * The sign-in answer, the same for a password sign-in and a completed
     * code (portal design §3).
     *
     * @return array<string, mixed>
     */
    public function loginBody(PortalUser $portalUser, NewAccessToken $token, bool $termsRequired): array
    {
        $member = $portalUser->borrower;
        $expiresAt = $token->accessToken->expires_at;

        return [
            'token' => $token->plainTextToken,
            'expires_in' => $expiresAt === null ? null : max(0, (int) round(now()->diffInSeconds($expiresAt))),
            'idle_minutes' => $this->settings->current()->idle_minutes,
            'member' => [
                'first_name' => $member->first_name,
                'member_number' => MemberNumber::of($member),
            ],
            'terms_required' => $termsRequired,
        ];
    }

    /**
     * Delete the member's tokens, all of them or all but one. Returns how many.
     */
    public function revoke(PortalUser $portalUser, ?int $exceptTokenId = null): int
    {
        return $portalUser->tokens()
            ->when($exceptTokenId !== null, fn ($tokens) => $tokens->whereKeyNot($exceptTokenId))
            ->delete();
    }

    /**
     * The member's live sessions, newest first.
     *
     * @return list<array{id: int, device: string, ip_address: ?string, created_at: ?Carbon, last_active_at: ?Carbon, expires_at: ?Carbon, current: bool}>
     */
    public function list(PortalUser $portalUser, ?int $currentTokenId): array
    {
        $tokens = $portalUser->tokens()
            ->where(fn ($live) => $live->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('id')
            ->get();

        $addresses = PortalLoginEvent::query()
            ->where('portal_user_id', $portalUser->getKey())
            ->whereIn('token_id', $tokens->modelKeys())
            ->pluck('ip_address', 'token_id');

        return $tokens->map(fn (PersonalAccessToken $token) => [
            'id' => (int) $token->getKey(),
            'device' => (string) $token->name,
            'ip_address' => $addresses[$token->getKey()] ?? null,
            'created_at' => $token->created_at,
            'last_active_at' => $token->last_used_at ?? $token->created_at,
            'expires_at' => $token->expires_at,
            'current' => $currentTokenId !== null && (int) $token->getKey() === $currentTokenId,
        ])->values()->all();
    }

    /**
     * A device name in words from a user agent ("Chrome on Android"), at most
     * 120 characters. Only the browser and system families, never the full
     * string.
     */
    public static function device(?string $userAgent): string
    {
        $agent = (string) $userAgent;

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => null,
        };

        $system = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'Mac',
            str_contains($agent, 'CrOS') => 'Chromebook',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        $name = match (true) {
            $browser !== null && $system !== null => "{$browser} on {$system}",
            $browser !== null => $browser,
            $system !== null => "A browser on {$system}",
            default => 'Unknown device',
        };

        return Str::limit($name, 120, '');
    }

    /** A user agent as a login event keeps it: valid UTF-8, capped. */
    public static function cleanUserAgent(?string $userAgent): ?string
    {
        return AuditLogService::cleanHeader($userAgent);
    }
}
