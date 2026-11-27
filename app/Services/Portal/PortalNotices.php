<?php

namespace App\Services\Portal;

use App\Models\PortalUser;
use App\Notifications\PortalNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * In-app notices to members (portal design §7): the database channel only,
 * never SMS or email. Written after commit, only to a member whose portal
 * account is active, and a failure is logged and never fails the action.
 */
final class PortalNotices
{
    public const CATEGORY_SECURITY = 'security';

    public const CATEGORY_REQUESTS = 'requests';

    public const CATEGORY_LOANS = 'loans';

    public const CATEGORY_SAVINGS = 'savings';

    public const CATEGORY_SHARE_CAPITAL = 'share_capital';

    public const CATEGORIES = [
        self::CATEGORY_SECURITY, self::CATEGORY_REQUESTS, self::CATEGORY_LOANS,
        self::CATEGORY_SAVINGS, self::CATEGORY_SHARE_CAPITAL,
    ];

    /**
     * @param  string|null  $link  a `/member/...` path, or null
     */
    public function send(PortalUser $portalUser, string $category, string $title, string $body, ?string $link = null): void
    {
        try {
            DB::afterCommit(function () use ($portalUser, $category, $title, $body, $link): void {
                try {
                    $fresh = $portalUser->fresh();

                    if ($fresh === null || $fresh->status !== PortalUser::STATUS_ACTIVE) {
                        return;
                    }

                    $fresh->notify(new PortalNotice($category, $title, $body, $link));
                } catch (Throwable $e) {
                    $this->logFailure($portalUser, $category, $e);
                }
            });
        } catch (Throwable $e) {
            $this->logFailure($portalUser, $category, $e);
        }
    }

    public function newSignIn(PortalUser $portalUser, string $device): void
    {
        $this->send($portalUser, self::CATEGORY_SECURITY, 'New sign-in', "Your account was signed in on {$device}. If this wasn't you, report it from Security and change your password.", '/member/security');
    }

    public function passwordChanged(PortalUser $portalUser): void
    {
        $this->send($portalUser, self::CATEGORY_SECURITY, 'Password changed', 'Your portal password was changed and your other devices were signed out. If this wasn\'t you, contact your branch.', '/member/security');
    }

    public function passwordReset(PortalUser $portalUser): void
    {
        $this->send($portalUser, self::CATEGORY_SECURITY, 'Password reset', 'Your portal password was reset with a code from your branch, and every other device was signed out.', '/member/security');
    }

    public function signedOutByStaff(PortalUser $portalUser): void
    {
        $this->send($portalUser, self::CATEGORY_SECURITY, 'Signed out by your branch', 'Your branch signed your account out of every device. Sign in again to continue.', '/member/security');
    }

    public function loginReported(PortalUser $portalUser): void
    {
        $this->send($portalUser, self::CATEGORY_SECURITY, 'Sign-in reported', 'You reported a sign-in you don\'t recognise. Your other devices were signed out; please change your password now.', '/member/security');
    }

    private function logFailure(PortalUser $portalUser, string $category, Throwable $e): void
    {
        Log::error('A member portal notice could not be written; the action was kept.', [
            'portal_user_id' => $portalUser->getKey(),
            'category' => $category,
            'exception' => $e,
        ]);
    }
}
