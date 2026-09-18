<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Borrower;
use App\Models\Document;
use App\Services\SignedFileLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves borrower KYC documents and photos off the private disk.
 *
 * These files used to sit on the public disk, which nginx serves directly with
 * no authentication — anyone holding the URL could fetch a borrower's valid ID.
 * They now live on `private`, which has no symlink into public/, and are only
 * reachable through the temporary signed URLs minted below.
 *
 * The routes are gated by `signed` rather than `auth:sanctum` on purpose: these
 * URLs end up in `<img src>`, which cannot send an Authorization header. The
 * signature is what authenticates the request, and it expires — see
 * SignedFileLink::TTL_MINUTES. Links are only ever minted while serialising a
 * response for a caller who already passed the API's own authorisation, so an
 * unauthorised user never receives one to begin with.
 *
 * Expiry alone was not enough. A signature is the whole credential here, so a
 * link that had already been handed out survived the revocation of every token
 * its owner held — an administrator resetting a compromised account still left
 * that account's outstanding links streaming borrower identity documents for
 * the rest of their 30-minute window. Each link now names the user it was
 * minted for and the value of that user's `file_link_version`, inside the
 * signature, and every request re-checks the pair. See App\Services\SignedFileLink.
 */
class FileController extends Controller
{
    /**
     * How long a minted file link stays valid.
     *
     * Kept as an alias of SignedFileLink::TTL_MINUTES so existing references
     * to the controller constant keep resolving to one number.
     */
    public const LINK_TTL_MINUTES = SignedFileLink::TTL_MINUTES;

    public function document(Request $request, Document $document): StreamedResponse
    {
        $this->assertLinkIsStillLive($request);

        return $this->stream($document->file_path, $document->original_filename);
    }

    public function borrowerPhoto(Request $request, Borrower $borrower): StreamedResponse
    {
        $this->assertLinkIsStillLive($request);

        abort_if(! $borrower->photo_path, 404);

        return $this->stream($borrower->photo_path);
    }

    /**
     * Refuse a link whose minting user has had their credentials rotated.
     *
     * 403 rather than 404: the signature proves the link was genuinely issued
     * by this application, so there is no existence to protect here, and a
     * distinct status is what lets a client tell "this link is stale, re-fetch
     * the record" apart from "this file is gone".
     */
    private function assertLinkIsStillLive(Request $request): void
    {
        abort_unless(
            SignedFileLink::bindingIsStillValid($request),
            403,
            'This link is no longer valid.',
        );
    }

    private function stream(?string $path, ?string $downloadName = null): StreamedResponse
    {
        $disk = Storage::disk('private');

        abort_if(! $path || ! $disk->exists($path), 404);

        // Inline so the browser renders it in an <img> or preview pane rather
        // than downloading it.
        return $disk->response($path, $downloadName, [
            // These are personal identity documents — never let a shared cache
            // hold on to one.
            'Cache-Control' => 'private, max-age=0, no-store',
        ]);
    }
}
