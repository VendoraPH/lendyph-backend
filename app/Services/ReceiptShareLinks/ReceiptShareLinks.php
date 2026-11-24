<?php

namespace App\Services\ReceiptShareLinks;

use App\Models\ReceiptShareLink;
use App\Models\Repayment;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Receipt share links (finish run round 2, decision 6): a staff member with
 * `payments:view` and the payment's branch makes a link that opens that one
 * receipt, masked (PublicReceipt), without signing in.
 *
 * - The token is 32 random bytes, base64url (43 characters). Only its SHA-256
 *   is stored, so it is returned once, when the link is made, and never again.
 * - A link works for 30 days unless it is revoked first. A receipt has at most
 *   one working link: a second is refused until the first is revoked or has
 *   expired, even when two staff ask at once (the receipt's row lock).
 * - Making, revoking and each public view write an audit row on the payment.
 *   A view is the system's, and records a keyed hash of the viewer's address
 *   instead of the address.
 * - An unknown, expired or revoked token, a malformed one, and every route
 *   while the switch is off, answer the same 404 (self::notFound()).
 */
final class ReceiptShareLinks
{
    public const TTL_DAYS = 30;

    /** 32 bytes, base64url without padding. */
    public const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

    public const ALREADY_ACTIVE = 'This receipt already has a working share link. Revoke it first to make a new one.';

    public const NONE_ACTIVE = 'This receipt has no working share link to revoke.';

    /** The one answer to every refused public view, and to every route while the switch is off. */
    public const NOT_FOUND = 'Not found.';

    public function enabled(): bool
    {
        return config('receipt_share_links.enabled') === true;
    }

    /**
     * The same 404 for every refusal: unknown, expired, revoked or malformed
     * tokens and the switch being off can't be told apart.
     */
    public static function notFound(): JsonResponse
    {
        return response()->json(['message' => self::NOT_FOUND], 404);
    }

    /** The receipt's newest link, whatever its status, or null when it has never had one. */
    public function latest(Repayment $repayment): ?ReceiptShareLink
    {
        return ReceiptShareLink::query()
            ->where('repayment_id', $repayment->getKey())
            ->with(['creator', 'revoker'])
            ->latest('id')
            ->first();
    }

    /**
     * Make a link for the receipt.
     *
     * @return array{0: ReceiptShareLink, 1: string} the link and its token, which is never readable again
     */
    public function create(Repayment $repayment, User $by): array
    {
        return DB::transaction(function () use ($repayment, $by): array {
            $this->lockReceipt($repayment);

            if (ReceiptShareLink::query()->where('repayment_id', $repayment->getKey())->active()->exists()) {
                throw self::conflict(self::ALREADY_ACTIVE);
            }

            $token = self::newToken();
            $link = ReceiptShareLink::query()->create([
                'repayment_id' => $repayment->getKey(),
                'token_hash' => ReceiptShareLink::hashToken($token),
                'created_by' => $by->getKey(),
                'expires_at' => now()->addDays(self::TTL_DAYS),
            ]);

            AuditLogService::log(
                'receipt_share_link_created',
                $repayment,
                null,
                ['share_link_id' => $link->id, 'expires_at' => $link->expires_at->toDateTimeString()],
                sprintf('Share link made for receipt %s, working until %s.', $repayment->receipt_number, $link->expires_at->format('M j, Y g:i A')),
            );

            return [$link->load(['creator', 'revoker']), $token];
        });
    }

    /** Revoke the receipt's working link: it stops opening at once. */
    public function revoke(Repayment $repayment, User $by): ReceiptShareLink
    {
        return DB::transaction(function () use ($repayment, $by): ReceiptShareLink {
            $this->lockReceipt($repayment);

            $link = ReceiptShareLink::query()
                ->where('repayment_id', $repayment->getKey())
                ->active()
                ->latest('id')
                ->first();

            if ($link === null) {
                throw self::conflict(self::NONE_ACTIVE);
            }

            $link->forceFill(['revoked_at' => now(), 'revoked_by' => $by->getKey()])->save();

            AuditLogService::log(
                'receipt_share_link_revoked',
                $repayment,
                ['share_link_id' => $link->id, 'status' => ReceiptShareLink::STATUS_ACTIVE],
                ['share_link_id' => $link->id, 'status' => ReceiptShareLink::STATUS_REVOKED],
                sprintf('Share link for receipt %s revoked.', $repayment->receipt_number),
            );

            return $link->load(['creator', 'revoker']);
        });
    }

    /**
     * The working link a public token opens, or null. Unknown, expired and
     * revoked tokens take the same single lookup; a malformed one takes none.
     */
    public function resolve(string $token): ?ReceiptShareLink
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        return ReceiptShareLink::query()
            ->where('token_hash', ReceiptShareLink::hashToken($token))
            ->active()
            ->first();
    }

    /**
     * A public view's audit row, on the payment: the system's (no user), with
     * a keyed hash of the viewer's address in place of the address.
     */
    public function recordView(ReceiptShareLink $link, Repayment $repayment, Request $request): void
    {
        AuditLogService::log(
            'receipt_share_link_opened',
            $repayment,
            null,
            ['share_link_id' => $link->id],
            sprintf('Shared receipt %s was opened from its share link.', $repayment->receipt_number),
            ipAddress: self::addressHash($request->ip()),
            asSystem: true,
        );
    }

    /**
     * The viewer's address as an audit row keeps it: "hash:" and the first
     * 32 hex characters of its HMAC-SHA256 under the app key. The same address
     * gives the same hash, so repeat views can be told apart from new viewers,
     * but the address itself can't be read back (a bare SHA-256 of an IPv4
     * address can be reversed by trying them all).
     */
    public static function addressHash(?string $ip): string
    {
        return 'hash:'.substr(hash_hmac('sha256', 'receipt-share-view|'.(string) $ip, (string) config('app.key')), 0, 32);
    }

    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * One link at a time per receipt: two requests for the same receipt wait
     * on its row, so the second sees the first's link. Nothing else is locked
     * while this is held.
     */
    private function lockReceipt(Repayment $repayment): void
    {
        Repayment::query()->whereKey($repayment->getKey())->lockForUpdate()->value('id');
    }

    private static function conflict(string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message], 409));
    }
}
