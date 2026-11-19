<?php

namespace App\Services\Amla\Cases;

use App\Models\AmlaDetectionLock;
use Illuminate\Support\Carbon;

/**
 * Reference numbers for AMLA cases and reports, taken under a lock on the
 * named row the case migration writes ("cases", "reports"), so two people
 * saving at once never get the same one. Call inside a transaction.
 */
final class References
{
    public function next(string $lock, string $prefix, callable $lastId): string
    {
        AmlaDetectionLock::query()->where('name', $lock)->lockForUpdate()->firstOrFail();

        return $prefix.'-'.Carbon::now()->format('Ymd').'-'.str_pad((string) ((int) $lastId() + 1), 6, '0', STR_PAD_LEFT);
    }
}
