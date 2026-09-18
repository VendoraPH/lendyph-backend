<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

/**
 * Mints and validates the temporary signed URLs that serve borrower KYC files.
 *
 * These links are authenticated by their signature and nothing else, because
 * they are consumed by <img src>, which cannot send an Authorization header.
 * That left one gap: a password reset revokes every token the user holds, but
 * it could not reach a link that had already been handed out, so a link minted
 * shortly before a reset kept streaming identity documents for the rest of its
 * 30-minute window. The FileController docblock has always claimed "a link
 * cannot outlive the session that produced it"; this is what makes that true.
 *
 * The link carries the minting user's id and their `file_link_version`. Both
 * sit inside the signed payload, so a caller can neither strip them nor point
 * them at a different user — tampering invalidates the signature. Bumping the
 * user's counter therefore kills every link minted for them, immediately.
 *
 * ## What is deliberately NOT bound
 *
 * The minting TOKEN. Binding to `personal_access_tokens.id` would be tighter,
 * but POST /auth/refresh deletes the old token and issues a new one, so every
 * image on an open page would break the moment the frontend refreshed its
 * session — and a plain logout would break links the user still legitimately
 * had on screen. The counter invalidates on the event that actually matters, a
 * forced credential rotation, and leaves ordinary session churn alone.
 *
 * ## Anonymous links
 *
 * Public registration mints links for an applicant who has no account at all:
 * POST /borrowers/{id}/photo and /valid-ids answer with a URL while the caller
 * holds only a submission token. There is no user to bind to and no password
 * that could be reset, so those links are minted unbound and expire on time as
 * before. This cannot be used as a downgrade: an attacker cannot remove the
 * binding from a bound link without breaking its signature.
 */
class SignedFileLink
{
    /**
     * How long a minted file link stays valid.
     *
     * Matches the default API token lifetime in config('auth.token_timeout').
     */
    public const TTL_MINUTES = 30;

    /**
     * Build a temporary signed route, bound to the current user when there is
     * one.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function to(string $routeName, array $parameters): string
    {
        $user = Auth::user();

        if ($user) {
            $parameters['u'] = $user->getKey();
            $parameters['fv'] = (int) ($user->file_link_version ?? 0);
        }

        return URL::temporarySignedRoute(
            $routeName,
            now()->addMinutes(self::TTL_MINUTES),
            $parameters,
        );
    }

    /**
     * Whether the credentials this link was minted against are still current.
     *
     * True for an unbound link — see the note on anonymous links above. False
     * once the named user's counter has moved on, or if that user is gone.
     */
    public static function bindingIsStillValid(Request $request): bool
    {
        $userId = $request->query('u');

        if ($userId === null) {
            return true;
        }

        // Scalars only. An array here (`?u[]=1`) cannot actually be reached —
        // the signature covers the whole query string, so a tampered link is
        // refused by the `signed` middleware long before this runs — but
        // User::find() on an array returns a Collection, and a truthy
        // Collection would walk straight past the guard below.
        if (! is_scalar($userId) || ! is_scalar($request->query('fv'))) {
            return false;
        }

        $user = User::find($userId);

        if (! $user) {
            return false;
        }

        return (int) $user->file_link_version === (int) $request->query('fv');
    }
}
