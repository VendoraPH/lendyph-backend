<?php

namespace App\Services\CsvImport;

use Illuminate\Support\Facades\Log;
use PDOException;
use Throwable;

/**
 * A description of a Throwable that is safe to persist, to log, and to hand to
 * an HTTP client.
 *
 * ## Why a raw exception message may never leave this class
 *
 * A Laravel QueryException's message is the failing SQL WITH THE BINDINGS
 * SUBSTITUTED IN. For this importer that means one duplicate-key failure
 * produces a string containing the member's name, birthdate, address, contact
 * number, employer and income — their entire record, verbatim, as a single line
 * of text. Interpolating `$e->getMessage()` therefore writes that record into
 * every place the error travels:
 *
 *  - `csv_import_rows.result_message`, which the admin error screen renders and
 *    the `errors.csv` export streams to a browser;
 *  - `csv_import_runs.failure_reason`, which the status endpoint returns;
 *  - `storage/logs/laravel.log`, which is the `single` channel: one file, never
 *    rotated, no scrubbing, mode 644.
 *
 * And it is not one member. A SYSTEMIC database fault — a lock-wait timeout, a
 * deadlock under the chunk's `lockForUpdate`, a poisoned sequence (see
 * Borrower::booted()) — fails every row it touches, so the log gets one such
 * line PER MEMBER. That is the cooperative's whole membership register replayed
 * into a flat file, which is precisely what the rest of this feature is built to
 * prevent: the assembled CSV is deleted on a terminal phase for exactly this
 * reason.
 *
 * ## What is safe
 *
 * Everything this class emits is either code (a class name) or a numeric error
 * identifier, never a value that came out of the file or the database:
 *
 *  - `get_class($e)` — a symbol from this repository.
 *  - `errorInfo[0]` — the SQLSTATE, e.g. `23000`.
 *  - `errorInfo[1]` — the driver's own code, e.g. `1062` for a duplicate key.
 *  - `getCode()`.
 *
 * `errorInfo[2]` is the driver's message and is deliberately NOT read. Note
 * that even that one is unsafe on its own: MySQL's duplicate-key text quotes the
 * offending VALUE.
 *
 * ## Where the full message went
 *
 * self::recordDiagnostics() writes it to a dedicated `csv-import` channel, which
 * is off by default, has its own file, restricted permissions and a retention
 * window. That is the only sanctioned place for it — never the shared `single`
 * channel, and never a column an HTTP endpoint streams.
 */
final class ImportErrorDigest
{
    /**
     * The dedicated, restricted, opt-in channel for the full message.
     */
    public const DIAGNOSTIC_CHANNEL = 'csv-import';

    /**
     * Log context for a Throwable: what it was and, if the database refused,
     * which error it refused with.
     *
     * @return array<string, string>
     */
    public static function context(Throwable $e): array
    {
        $context = ['exception' => $e::class];

        $driver = self::driverException($e);

        if ($driver === null) {
            return $context;
        }

        if ($driver::class !== $e::class) {
            $context['driver_exception'] = $driver::class;
        }

        $info = is_array($driver->errorInfo) ? $driver->errorInfo : [];

        if (isset($info[0])) {
            $context['sql_state'] = (string) $info[0];
        }

        if (isset($info[1])) {
            $context['driver_code'] = (string) $info[1];
        }

        $code = (string) $driver->getCode();

        if ($code !== '' && $code !== '0') {
            $context['code'] = $code;
        }

        return $context;
    }

    /**
     * What goes in `csv_import_rows.result_message` when a row throws.
     *
     * Fixed prose keyed to the line number, so the operator can find the row in
     * their spreadsheet and the engineer can find the same line number in the
     * run log. The ONLY variable part is the driver's numeric code, because an
     * import that fails 1,200 rows with 1062 (duplicate) needs a different
     * answer from one that fails 1,200 rows with 1205 (lock wait), and making
     * the operator open a log to learn which would waste a support cycle on
     * every occurrence.
     */
    public static function forRow(Throwable $e, int $lineNumber): string
    {
        $code = self::driverCode($e);

        return $code === null
            ? "Row {$lineNumber} could not be written (unexpected error). See the run log."
            : "Row {$lineNumber} could not be written (database error {$code}). See the run log.";
    }

    /**
     * What goes in `csv_import_runs.failure_reason` when a whole run is written
     * off after throwing. Same rule, same reason — the status endpoint returns
     * this column to the browser.
     *
     * This one names the exception CLASS where forRow() does not, and the
     * difference is deliberate. A class name is a symbol out of this repository,
     * never a value out of the file, so it is safe by construction; and unlike a
     * row stamp — of which there may be twelve thousand, all identical — this
     * appears once per run and is the operator's only clue about what went
     * wrong. Support asking "what does the status screen say" should get an
     * answer worth having.
     */
    public static function forRun(Throwable $e, int $runId): string
    {
        $code = self::driverCode($e);

        $cause = $code === null
            ? 'an unexpected error ('.class_basename($e).')'
            : "a database error ({$code})";

        return "This run was stopped after {$cause}. Nothing further was written; "
            ."see the run log for run #{$runId}.";
    }

    /**
     * Send the full, unredacted message to the dedicated channel.
     *
     * OFF unless `LOG_CSV_IMPORT_DIAGNOSTICS=true`, and it must stay that way on
     * any box holding real member data. It exists so that the answer to "I need
     * the actual message to debug this" is a switch with its own file, its own
     * 0600 permissions and its own retention — rather than someone quietly
     * putting `$e->getMessage()` back into the shared log, where it would never
     * rotate and never be scrubbed.
     *
     * @param  array<string, mixed>  $context
     */
    public static function recordDiagnostics(Throwable $e, array $context = []): void
    {
        if (! config('logging.csv_import_diagnostics', false)) {
            return;
        }

        try {
            Log::channel(self::DIAGNOSTIC_CHANNEL)->error('csv-import: full exception detail', $context + [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        } catch (Throwable) {
            // Diagnostics are a convenience. A misconfigured channel must never
            // take down the import, and must never fall back to the shared
            // channel — falling back is the leak this whole class prevents.
        }
    }

    /**
     * The driver's own error number, e.g. `1062`, or null when the failure had
     * nothing to do with the database.
     */
    public static function driverCode(Throwable $e): ?string
    {
        $driver = self::driverException($e);

        if ($driver === null || ! is_array($driver->errorInfo)) {
            return null;
        }

        return isset($driver->errorInfo[1]) ? (string) $driver->errorInfo[1] : null;
    }

    /**
     * The first PDOException in the chain.
     *
     * Walked rather than type-checked on `$e` alone because a QueryException IS
     * a PDOException but carries its driver's `errorInfo` copied from the
     * PDOException it wrapped, and because anything else in the stack may have
     * wrapped either of them in turn. `$seen` guards a self-referencing chain,
     * which is rare but would otherwise hang the process inside an error
     * handler.
     */
    private static function driverException(Throwable $e): ?PDOException
    {
        $seen = [];

        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (in_array(spl_object_id($current), $seen, true)) {
                return null;
            }

            $seen[] = spl_object_id($current);

            if ($current instanceof PDOException) {
                return $current;
            }
        }

        return null;
    }
}
