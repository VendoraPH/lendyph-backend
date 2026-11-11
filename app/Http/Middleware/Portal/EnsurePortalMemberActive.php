<?php

namespace App\Http\Middleware\Portal;

use App\Models\PortalUser;
use App\Services\Portal\Auth\PortalLogin;
use App\Services\Portal\PortalSettings;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Revocation, checked on every signed-in request (portal design §1): the
 * portal is on, the portal account is active and the member is active. A
 * bulk status update that skips model events is still caught here. Any
 * failure deletes the token.
 */
class EnsurePortalMemberActive
{
    public function __construct(private readonly PortalSettings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var PortalUser $portalUser */
        $portalUser = $request->user();
        $fresh = PortalUser::query()->with('borrower:id,status')->find($portalUser->getKey());

        $refusal = match (true) {
            ! $this->settings->enabled() => [503, PortalLogin::PORTAL_OFF, 'portal_off'],
            $fresh === null || $fresh->status === PortalUser::STATUS_DISABLED => [403, PortalLogin::DISABLED, 'portal_access_disabled'],
            $fresh->status !== PortalUser::STATUS_ACTIVE => [403, 'Your portal account is locked. Please contact your branch.', 'portal_account_locked'],
            $fresh->borrower?->status !== 'active' => [403, PortalLogin::MEMBER_INACTIVE, 'membership_inactive'],
            default => null,
        };

        if ($refusal !== null) {
            $token = $portalUser->currentAccessToken();

            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            return response()->json(['success' => false, 'message' => $refusal[1], 'code' => $refusal[2]], $refusal[0]);
        }

        return $next($request);
    }
}
