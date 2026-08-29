<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogService
{
    /**
     * Whether the Auditable trait's automatic per-model rows are suppressed.
     *
     * Held here rather than on the trait itself because a static property
     * declared in a trait is a SEPARATE variable in every class that uses it —
     * `Borrower::$flag` and `Loan::$flag` would be two different booleans, so a
     * single scope could never cover both models at once. One flag on the class
     * the trait already calls into covers every auditable model there is.
     */
    private static bool $modelAuditingSuppressed = false;

    /**
     * Run a callback with the Auditable trait's automatic rows turned off,
     * leaving every other model event — and every DIRECT call to self::log() —
     * running exactly as normal.
     *
     * This exists for the CSV importer and the reasoning is worth keeping.
     *
     * The obvious ways to stop 12,000 borrower-created audit rows are
     * `Model::withoutEvents()` and `saveQuietly()`, and both are silently
     * catastrophic here: `Borrower::booted()`'s `created` hook is also what
     * creates the member's ShareCapitalPledge, so suppressing model events
     * suppresses the pledge too. Nothing errors. The members import, the import
     * reports success, and every "Pledge Amt" in the file is simply gone — and
     * it cannot be recovered afterwards, because `pledges:backfill` hardcodes
     * `amount = 0`. The damage would surface months later as members whose
     * share capital target reads zero.
     *
     * So the suppression is placed at the ONE thing that should not run rather
     * than at the event that carries it. Every other `created`, `updated` and
     * `deleted` hook still fires, including any added later, and a direct
     * self::log() — the importer's single summary row — is untouched.
     *
     * Re-entrant and exception-safe: the previous value is restored in a
     * `finally`, so a throw inside the callback cannot leave auditing off for
     * the rest of the process.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutModelAuditing(callable $callback): mixed
    {
        $previous = self::$modelAuditingSuppressed;
        self::$modelAuditingSuppressed = true;

        try {
            return $callback();
        } finally {
            self::$modelAuditingSuppressed = $previous;
        }
    }

    /**
     * Read by App\Traits\Auditable's three listeners, and by nothing else.
     */
    public static function modelAuditingIsSuppressed(): bool
    {
        return self::$modelAuditingSuppressed;
    }

    /**
     * Write one audit entry.
     *
     * `$userId` and `$ipAddress` are trailing and optional, and default to the
     * authenticated user and the current request exactly as this method always
     * has. They exist because neither default means anything outside an HTTP
     * request: a queued job or a scheduled command has no `auth()` user, and it
     * has no inbound request either — Laravel's SetRequestForConsole bootstrapper
     * synthesises one with `Request::create()`, whose defaults make
     * `request()->ip()` the literal `127.0.0.1` for every background write on
     * every deployment. So the summary row a background process writes would
     * name nobody and claim to come from the loopback address of whichever box
     * happened to run it. The CSV importer is the case in point — it
     * is started by an admin through the UI but does its work later, on the
     * queue, and the person accountable for a bulk import of borrowers and loans
     * has to survive that hop. The caller captures both at the point the human
     * asked for the work and hands them back here.
     *
     * They are trailing so no existing caller has to change: none of them passes
     * more than five arguments today, and the majority pass named arguments
     * anyway.
     *
     * `user_agent` takes no override, and what it records for a background write
     * is NOT the honest null an earlier version of this note promised. The
     * synthesised console request above carries Symfony's own default header, so
     * every audit row a queued job or a scheduled command writes reads
     * `user_agent: "Symfony"` — verified by running it, not inferred. Noise
     * rather than a lie, since no browser is named, but it must not be read as
     * evidence that one was involved. Making it genuinely null means adding a
     * `$userAgent` parameter beside the two above; until somebody needs that,
     * `user_id` and `ip_address` are what identify a background write, and both
     * are already overridable.
     */
    public static function log(
        string $action,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?int $userId = null,
        ?string $ipAddress = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'auditable_type' => $auditable ? $auditable->getMorphClass() : null,
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => $ipAddress ?? request()->ip(),
            'user_agent' => request()->userAgent(),
            'description' => $description,
        ]);
    }
}
