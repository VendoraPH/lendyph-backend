<?php

namespace App\Http\Middleware\ReceiptShare;

use App\Services\ReceiptShareLinks\ReceiptShareLinks;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The server switch (RECEIPT_SHARE_LINKS_ENABLED, off by default): while it
 * is off every receipt share link route, staff and public, answers the one
 * 404 an unknown link gets. On the public route it runs ahead of the limiters
 * (bootstrap/app.php), so it is never a 429 instead.
 */
class EnsureReceiptShareLinksEnabled
{
    public function __construct(private readonly ReceiptShareLinks $links) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->links->enabled()) {
            return ReceiptShareLinks::notFound();
        }

        return $next($request);
    }
}
