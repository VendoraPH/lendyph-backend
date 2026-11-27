<?php

namespace App\Services\Portal\Observers;

use App\Models\PortalUser;
use App\Services\Accounting\Money;
use App\Services\Portal\PortalNotices;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The member's money notices (portal design §7), for the portal observers:
 * only to a member with an active portal account, written after commit by
 * PortalNotices (so a rolled-back write tells nobody), with the server's ₱
 * amount and no internal ids in the text. Anything failing here is logged
 * and never fails the money write that set it off.
 */
final class MoneyNotices
{
    public static function toMember(?int $borrowerId, string $category, string $title, string $body, ?string $link): void
    {
        if ($borrowerId === null) {
            return;
        }

        try {
            $portalUser = PortalUser::query()->where('borrower_id', $borrowerId)->where('status', PortalUser::STATUS_ACTIVE)->first();

            if ($portalUser === null) {
                return;
            }

            app(PortalNotices::class)->send($portalUser, $category, $title, $body, $link);
        } catch (Throwable $e) {
            Log::error('A member portal money notice could not be written; the money write was kept.', [
                'borrower_id' => $borrowerId,
                'category' => $category,
                'exception' => $e,
            ]);
        }
    }

    /** "₱1,500.50" from a stored peso amount. */
    public static function peso(mixed $pesos): string
    {
        return Money::format(Money::toCentavos((string) ($pesos ?? '0')) ?? 0);
    }
}
