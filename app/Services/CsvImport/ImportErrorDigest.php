<?php

namespace App\Services\CsvImport;

use App\Services\Diagnostics\ErrorDigest;
use Throwable;

/**
 * The importer's error digest: CSV-shaped prose over the application-wide
 * ErrorDigest.
 *
 * ## Why this is now two classes
 *
 * The rule this class was written to enforce — that a raw exception message
 * never reaches a response body or the shared log, because a QueryException's
 * message is the failing SQL with the bindings substituted in — is not an
 * importer rule. It applies wherever this application writes a member's row.
 * `BorrowerController::bulkDeactivate()` and `bulkDestroy()` had exactly the
 * same defect, in a worse place: they put `$e->getMessage()` in the HTTP
 * RESPONSE, not merely a log file, and the `Auditable` trait's `updated` and
 * `deleted` hooks copy the borrower's full attribute set into
 * `audit_logs.old_values`, so the statement that fails is one carrying the
 * entire member. `registrations:prune` had it by a third route again — a
 * DIRECT AuditLogService::log() call, which no `audit: false` suppresses.
 *
 * Reusing this class from a borrower endpoint would have meant a controller
 * importing `App\Services\CsvImport\ImportErrorDigest` to delete a member,
 * which is a lie about what the code is. So the general machinery — the
 * context, the driver code, the restricted-channel sink, the argument for all
 * three — moved to {@see ErrorDigest}, and what stayed here is what is
 * genuinely about importing: prose keyed to a LINE NUMBER and to a RUN.
 *
 * Every call site of this class is unchanged, and deliberately so: this file is
 * the importer's vocabulary and the importer should keep speaking it.
 * CsvImportExceptionMessageArchTest scans both files.
 */
final class ImportErrorDigest
{
    /**
     * The dedicated, restricted, opt-in channel for the full message.
     *
     * Kept as an alias rather than deleted: the importer's tests reconfigure
     * the channel through this constant, and it reads better at those call
     * sites than reaching across to another namespace would.
     */
    public const DIAGNOSTIC_CHANNEL = ErrorDigest::RESTRICTED_CHANNEL;

    /**
     * Log context for a Throwable: the exception class, the SQLSTATE and the
     * driver's numeric code. Never the message. See ErrorDigest.
     *
     * @return array<string, string>
     */
    public static function context(Throwable $e): array
    {
        return ErrorDigest::context($e);
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
        return ErrorDigest::forSubject($e, "Row {$lineNumber}", 'written', 'See the run log.');
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
     * OFF unless `LOG_CSV_IMPORT_DIAGNOSTICS=true` — this is the caller that
     * key was named for, and the only one that takes ErrorDigest's DEFAULT
     * flag. The borrower paths pass their own, so turning the importer's
     * switch on for an import incident does not also start capturing member
     * records from anywhere else.
     *
     * The FILE is shared with them, so the import's lines are tagged to stay
     * attributable in it.
     *
     * @param  array<string, mixed>  $context
     */
    public static function recordDiagnostics(Throwable $e, array $context = []): void
    {
        ErrorDigest::recordDiagnostics($e, $context + ['source' => 'csv-import']);
    }

    /**
     * The driver's own error number, e.g. `1062`, or null when the failure had
     * nothing to do with the database.
     */
    public static function driverCode(Throwable $e): ?string
    {
        return ErrorDigest::driverCode($e);
    }
}
