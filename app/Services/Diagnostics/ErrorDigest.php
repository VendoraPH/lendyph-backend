<?php

namespace App\Services\Diagnostics;

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
 * SUBSTITUTED IN — still true on Laravel 13, whose `$maskBindings` defaults to
 * false. Anywhere this application writes a member's row, that makes one
 * database error a string containing their name, birthdate, address, contact
 * number, employer and income: the whole record, verbatim, as a single line of
 * text.
 *
 * It is not only the statement the caller wrote. `App\Traits\Auditable` hangs
 * `created`/`updated`/`deleted` hooks on every audited model and copies the
 * model's FULL attribute set into `audit_logs.old_values`. So an ordinary
 * `$borrower->update(['status' => 'inactive'])` — whose own SQL names one
 * harmless column — fires a second INSERT carrying the entire member, and it is
 * THAT statement's failure that produces the dangerous message. A caller cannot
 * judge the sensitivity of an exception by looking at the line that threw.
 *
 * ## What is safe
 *
 * Everything this class emits is either code (a class name) or a numeric error
 * identifier, never a value that came out of a request, a file or the database:
 *
 *  - `get_class($e)` — a symbol from this repository.
 *  - `errorInfo[0]` — the SQLSTATE, e.g. `23000`.
 *  - `errorInfo[1]` — the driver's own code, e.g. `1062` for a duplicate key,
 *    `1451` for a restricted delete.
 *  - `getCode()`.
 *
 * `errorInfo[2]` is the driver's message and is deliberately NOT read. Note
 * that even that one is unsafe on its own: MySQL's duplicate-key text quotes
 * the offending VALUE.
 *
 * ## No allowlist of "safe" exception classes
 *
 * There is deliberately no list of exception types whose message may be passed
 * through. A driver message is the worst case, not the only one: PHP's own
 * exceptions quote their input often enough — `DateMalformedStringException`,
 * `JsonException`, `InvalidArgumentException` — that an allowlist is a standing
 * invitation to get one wrong, and it would be wrong silently. The rule is
 * flat: no exception's own text reaches a response body or the shared log,
 * whatever raised it.
 *
 * ## Where the full message went
 *
 * self::recordDiagnostics() writes it to a dedicated channel that is off by
 * default and has its own file, restricted permissions and a retention window.
 * That is the only sanctioned place for it — never the shared `single` channel,
 * and never a column or a JSON key an HTTP endpoint streams.
 */
final class ErrorDigest
{
    /**
     * The dedicated, restricted, opt-in channel for the full message.
     *
     * ## On the name
     *
     * It says `csv-import` because the importer needed this first and built it;
     * the channel is now the application's one restricted diagnostics sink and
     * the borrower bulk endpoints write to it too. The name is a misnomer and
     * is kept on purpose.
     *
     * What this class depends on is the channel's PROPERTIES, not its label:
     * its own file (so member data is never interleaved with the log everyone
     * tails), mode 0600 rather than `single`'s world-readable 644, daily
     * rotation with a retention window, and a kill switch that is off by
     * default. See config/logging.php.
     *
     * Renaming it is a deployment-coordinated change, not a refactor: it is
     * configured on ten separate boxes, and if any one of them has it set to
     * chase a live incident, a rename turns that off silently — which is the
     * one failure mode a diagnostics switch must not have.
     */
    public const RESTRICTED_CHANNEL = 'csv-import';

    /**
     * The DEFAULT config key gating whether anything is ever written to the
     * channel above. Off by default, and it must stay off on any box holding
     * real member data. Named for the same historical reason as the channel.
     *
     * ## Why this is a default and not the flag
     *
     * The channel is shared; the switch is not. `LOG_CSV_IMPORT_DIAGNOSTICS`
     * ships in .env.example and is therefore a real knob on ten boxes, so a
     * caller that reuses it inherits every box where somebody turned it on for
     * an unrelated incident — arming a writer that operator never agreed to.
     * That is the exact mirror of the rename problem above: one name failing
     * off, the other failing ON.
     *
     * So a caller writing about a different subject passes its own flag to
     * recordDiagnostics() — see BORROWER_DIAGNOSTICS_FLAG below — and
     * config/logging.php defines one per writer. Only the importer, whose
     * incident this key was named for, takes the default.
     */
    public const DIAGNOSTICS_FLAG = 'logging.csv_import_diagnostics';

    /**
     * The flag for everything that writes a BORROWER'S OWN ROW: the bulk
     * deactivate/delete endpoints and registrations:prune. (The prune
     * suppresses the Auditable hooks and still needs this — see the comment in
     * its catch block for the three reasons why.)
     *
     * New, and so set on no deployment — which is the whole point of it being
     * new. It cannot inherit a box where somebody turned the importer's switch
     * on last month and left it.
     *
     * It lives HERE, next to the flag it exists to be separate from, rather
     * than on either caller. The alternative was a constant on
     * BorrowerController, which would have made a console command import an
     * HTTP controller to read a config key — a worse dependency than the one it
     * would have removed. Config keys for this sink are properties of the sink.
     */
    public const BORROWER_DIAGNOSTICS_FLAG = 'logging.borrower_diagnostics';

    /**
     * Log context for a Throwable: what it was and, if the database refused,
     * which error it refused with.
     *
     * This is what belongs in the shared log. It is enough to tell a duplicate
     * key (1062) from a lock-wait timeout (1205) from a restricted delete
     * (1451) without opening a database, and it carries no cell value.
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
     * Fixed operator-facing prose for one thing that failed.
     *
     * Every part of the sentence is written HERE except the identifier the
     * caller already knew and the driver's numeric code. Nothing is taken from
     * the exception's text, so there is no path by which a binding, a cell or a
     * quoted input reaches the caller.
     *
     * The numeric code is the one variable part, and it earns its place: an
     * operator whose bulk delete failed on 1451 (the member still has a loan —
     * their problem, and actionable) needs a different answer from one that
     * failed on 1205 (lock wait — retry), and making them open a log to learn
     * which would waste a support cycle on every occurrence.
     *
     * @param  string  $subject  What failed, already safe: "Borrower 412",
     *                           "Row 27". An identifier the caller supplied,
     *                           never a value read back out of the record.
     * @param  string  $action  Past participle: "deleted", "deactivated",
     *                          "written".
     * @param  string  $pointer  Where the engineer should look.
     */
    public static function forSubject(Throwable $e, string $subject, string $action, string $pointer): string
    {
        $code = self::driverCode($e);

        return $code === null
            ? "{$subject} could not be {$action} (unexpected error). {$pointer}"
            : "{$subject} could not be {$action} (database error {$code}). {$pointer}";
    }

    /**
     * Send the full, unredacted message to the dedicated channel.
     *
     * OFF unless the flag above is set, and it must stay that way on any box
     * holding real member data. It exists so that the answer to "I need the
     * actual message to debug this" is a switch with its own file, its own 0600
     * permissions and its own retention — rather than someone quietly putting
     * `$e->getMessage()` back into the shared log, where it would never rotate
     * and never be scrubbed.
     *
     * Callers sharing one file should pass a `source` in the context so their
     * lines stay attributable, and anything that is not the importer should
     * pass its own `$flag` — the file is shared deliberately, the switch is
     * not. See DIAGNOSTICS_FLAG.
     *
     * @param  array<string, mixed>  $context
     * @param  string|null  $channel  Overrides RESTRICTED_CHANNEL. Must be at
     *                                least as restricted; there is no check,
     *                                because a check that reads the config of
     *                                an arbitrary channel cannot tell a
     *                                0600 daily file from a 644 one after a
     *                                deploy has touched it by hand.
     * @param  string|null  $flag  Overrides DIAGNOSTICS_FLAG. Required in
     *                             spirit for any caller that is not the CSV
     *                             importer: sharing a flag means one
     *                             feature's incident switch arms another
     *                             feature's capture of member records.
     */
    public static function recordDiagnostics(
        Throwable $e,
        array $context = [],
        ?string $channel = null,
        ?string $flag = null,
    ): void {
        if (! config($flag ?? self::DIAGNOSTICS_FLAG, false)) {
            return;
        }

        try {
            Log::channel($channel ?? self::RESTRICTED_CHANNEL)->error('full exception detail', $context + [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        } catch (Throwable) {
            // Diagnostics are a convenience. A misconfigured channel must never
            // take down the caller, and must never fall back to the shared
            // channel — falling back is the leak this whole class prevents.
        }
    }

    /**
     * The driver's own error number, e.g. `1451`, or null when the failure had
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
