<?php

use App\Http\Controllers\Api\BorrowerController;
use App\Services\CsvImport\ImportErrorDigest;
use App\Services\Diagnostics\ErrorDigest;

/**
 * Nothing on the CSV-import path may put an exception's own text into a log.
 *
 * A Laravel QueryException's message is the failing SQL WITH THE BINDINGS
 * SUBSTITUTED IN — still true on Laravel 13, whose `$maskBindings` defaults to
 * false. On this feature that makes one duplicate-key failure a member's name,
 * birthdate, address, contact number and income, verbatim, as a single line of
 * text. Every logging call in these files writes the `single` channel unless
 * told otherwise: one file, never rotated, no scrubbing, mode 644.
 * ImportErrorDigest exists so that the exception CLASS, the SQLSTATE and the
 * driver's numeric code go there instead, and so the full text has exactly one
 * sanctioned destination — the dedicated `csv-import` channel, off by default,
 * with its own permissions and its own retention window.
 *
 * ## Why this is a test and not a convention
 *
 * Because the convention did not hold. It was introduced with "every site
 * converted", and three sites in CsvImportUploadService::finaliseRun() were
 * missed — on the exact path the review was about. Nothing failed and nothing
 * warned. Worse, the files that DO obey the rule explain it in prose that names
 * `$e->getMessage()`, so a `grep` for that string reports the comments warning
 * against the call and buries the real ones among them. Reading by eye is how
 * the gap survived; reading by tokeniser is how it stops.
 *
 * ## Read as code, never as text
 *
 * The scan is tokenised. A `$e->getMessage()` inside a comment or a string is
 * not a hit, and a real call cannot hide behind whitespace, a line break or a
 * nullsafe operator.
 *
 * ## Four spellings, not one
 *
 * `getMessage()` was the first. `report()` was the second, and it was found BY
 * HAND rather than by this test — which is the whole argument for what follows.
 * A sink that can be found by eye can come back the same way. So the scan also
 * pins:
 *
 *  - `(string) $e` and `$e->__toString()`, which are WORSE than the two already
 *    pinned: a Throwable's string form is the message PLUS the full stack trace;
 *  - `$e->getTraceAsString()`;
 *  - the exception OBJECT handed to a log context — `['exception' => $e]` —
 *    which Monolog stringifies for exactly the same result. Note that
 *    `['exception' => $e::class]`, which is what ImportErrorDigest emits, is a
 *    class-name STRING and is not a hit.
 *
 * All four were clean when they were added; this is what keeps them that way.
 *
 * ## Scope
 *
 * Every file under app/Services/CsvImport/ — GLOBBED, so a service added later
 * is covered without anyone remembering to add it — plus the CSV-import console
 * commands, which catch the same exceptions and log them to the same channel,
 * plus app/Services/Diagnostics/, which is where the sanctioned sink now lives.
 *
 * That last one is not scope creep, it is the scope FOLLOWING THE CODE. The
 * general half of ImportErrorDigest moved to ErrorDigest when the borrower bulk
 * endpoints turned out to have the same defect in a worse place — in the
 * response body rather than a log. The one permitted `getMessage()` call moved
 * with it. Had the glob stayed as it was, this test would have gone on passing
 * while no longer reading the line it exists to bound, which is the failure mode
 * an arch test is least able to notice about itself.
 *
 * AppServiceProvider's CSV-import listener is deliberately NOT scanned. It is a
 * shared file whose other owners have unrelated reasons to call getMessage(),
 * and an arch test that fails somebody for a line in another feature is an arch
 * test that gets deleted. That site is pinned behaviourally instead — see
 * CsvImportUploadApiTest::test_a_failing_storage_release_does_not_fail_the_run_it_was_tidying_up_after().
 *
 * ## Two kinds of source: globbed whole, and bounded by reflection
 *
 * BorrowerController is scanned too, and it is the file this rule was RE-learnt
 * on: bulkDeactivate() and bulkDestroy() put $e->getMessage() straight into the
 * HTTP RESPONSE BODY, which is strictly worse than the log leaks above — a log
 * needs shell access, a response body is handed to whoever called the endpoint.
 * Until now its only guard was behavioural, and a behavioural guard covers the
 * methods somebody wrote a test for.
 *
 * It is NOT globbed, and app/Http/Controllers/ is not swept. Same argument as
 * the AppServiceProvider exclusion: that directory is full of files whose other
 * owners have their own reasons to read an exception, and an arch test that
 * fails an unrelated team for an unrelated line gets deleted rather than
 * obeyed. So the two methods are bounded BY REFLECTION to their own line
 * ranges — the same tool the sanctioned-sink exemption already uses — and this
 * test claims ownership of nothing else in the file. Rename either method and
 * the reflection throws, which is the right way to find out.
 *
 * (The file is still called CsvImportExceptionMessageArchTest, which is now a
 * half-truth. The rule was the importer's first; it is the application's.)
 *
 * No database and no application boot: this reads the files' own source.
 */

/**
 * The files that carry the invariant.
 *
 * @return list<string>
 */
$csvImportSources = static function (): array {
    $root = dirname(__DIR__, 2);

    $files = glob($root.'/app/Services/CsvImport/*.php') ?: [];

    // The shared diagnostics half, globbed for the same reason: it holds the
    // ONE sanctioned getMessage() call in the application, so a second class
    // landing beside it must not arrive unscanned.
    $files = [...$files, ...(glob($root.'/app/Services/Diagnostics/*.php') ?: [])];

    /*
     * The console side, named rather than globbed: app/Console/Commands holds a
     * dozen commands that have nothing to do with importing, and sweeping the
     * directory would quietly claim ownership of all of them.
     *
     * PruneAbandonedRegistrations is not an importer and is here anyway. It
     * writes a BORROWER'S row, which is the subject this rule is really about,
     * and it was converted to ErrorDigest in the same change that added the
     * borrower diagnostics flag — but nothing pinned that conversion. The bulk
     * endpoint tests only exercise bulk endpoints, and none of the prune's own
     * 15 cases assert on its catch block, so putting `$e->getMessage()` back
     * left the entire suite green. A converted call site with no guard is a
     * call site that un-converts itself the first time somebody debugs a prune
     * failure at 3am. Its catch binds `$e`, so all three scans below apply.
     */
    foreach (['ProcessCsvImports.php', 'RedactCsvImportRows.php', 'PruneAbandonedRegistrations.php'] as $command) {
        $path = $root.'/app/Console/Commands/'.$command;

        if (is_file($path)) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
};

/**
 * Files scanned only BETWEEN the line numbers of named methods, and the ranges
 * they are scanned between.
 *
 * Reflection rather than a hardcoded pair of line numbers, so that editing
 * anything above these methods cannot silently slide the window off them.
 *
 * @return array<string, list<array{0: int, 1: int}>>
 */
$boundedSources = static function (): array {
    static $bounded = null;

    if ($bounded !== null) {
        return $bounded;
    }

    $bounded = [];

    foreach ([BorrowerController::class => ['bulkDeactivate', 'bulkDestroy']] as $class => $methods) {
        foreach ($methods as $method) {
            $reflection = new ReflectionMethod($class, $method);

            $bounded[$reflection->getFileName()][] = [$reflection->getStartLine(), $reflection->getEndLine()];
        }
    }

    return $bounded;
};

/**
 * Whether a hit is somewhere this test actually claims.
 *
 * True for every line of a file scanned whole; true only inside the declared
 * ranges for a file scanned in part.
 */
$inScope = static function (string $file, int $line) use ($boundedSources): bool {
    $ranges = $boundedSources()[$file] ?? null;

    if ($ranges === null) {
        return true;
    }

    foreach ($ranges as [$start, $end]) {
        if ($line >= $start && $line <= $end) {
            return true;
        }
    }

    return false;
};

/**
 * The lines of a file on which a method named `$name` is actually CALLED on an
 * object, ignoring comments and strings entirely.
 *
 * @return list<int>
 */
$callsTo = static function (string $file, string $name): array {
    $tokens = PhpToken::tokenize(file_get_contents($file));
    $lines = [];

    foreach ($tokens as $position => $token) {
        if (! $token->is(T_STRING) || $token->text !== $name) {
            continue;
        }

        // Walk back past whitespace and comments to the token that decides what
        // this is: `->` or `?->` means a call on an object, anything else
        // (`function`, `::`, a bare identifier) does not.
        for ($previous = $position - 1; $previous >= 0; $previous--) {
            if ($tokens[$previous]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            if ($tokens[$previous]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                $lines[] = $token->line;
            }

            break;
        }
    }

    return $lines;
};

/**
 * The lines of a file on which a plain function named `$name` is called.
 *
 * @return list<int>
 */
$callsToFunction = static function (string $file, string $name): array {
    $tokens = PhpToken::tokenize(file_get_contents($file));
    $lines = [];

    foreach ($tokens as $position => $token) {
        if (! $token->is(T_STRING) || $token->text !== $name) {
            continue;
        }

        // Not a method call, not a declaration, not a class constant — and
        // followed by an opening parenthesis.
        for ($previous = $position - 1; $previous >= 0; $previous--) {
            if ($tokens[$previous]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            if ($tokens[$previous]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW])) {
                break;
            }

            for ($next = $position + 1; $next < count($tokens); $next++) {
                if ($tokens[$next]->is(T_WHITESPACE)) {
                    continue;
                }

                if ($tokens[$next]->text === '(') {
                    $lines[] = $token->line;
                }

                break;
            }

            break;
        }
    }

    return $lines;
};

/**
 * The variables in a file that hold a Throwable.
 *
 * DISCOVERED, not hardcoded. Every one is the binding of a `catch (... $var)`,
 * plus one hop of direct assignment (`$finaliserFailure = $e;`) because that is
 * how a caught exception is carried past its own catch block on this path. A
 * hardcoded list of names would go stale the first time somebody writes
 * `catch (Throwable $problem)`, and would go stale silently.
 *
 * Parameters typed `Throwable` ARE included, and the argument for leaving them
 * out did not survive contact with the file layout it assumed.
 *
 * It ran: the only Throwable parameters on this path belong to the sanctioned
 * handler, whose one permitted read is bounded by reflection anyway, and a new
 * leak arrives in a catch block rather than as a parameter. The second half is
 * still a fair bet. The first half stopped being true, and worse, it took this
 * test's coverage of ErrorDigest.php with it: that file's only catch is
 * `} catch (Throwable) {` with NO binding, so `$names` came back empty and the
 * whole file was skipped by the caller below. The class holding the
 * application's one deliberate getMessage() was the one file this test did not
 * read — and nothing said so, because `$scanned` stays comfortably non-zero on
 * the strength of the importer's many catch blocks.
 *
 * That gap was reachable, not theoretical: `(string) $e` or `['exception' => $e]`
 * inside context() or forSubject() would have passed all three tests here, and
 * context() goes to the SHARED log while forSubject() goes into a RESPONSE BODY.
 *
 * Including them costs nothing. `$stringificationsOf()` flags a bare variable
 * only where the next significant token closes the expression (`,`, `]`, `)`)
 * after a `=>`, so passing `$e` on to another call — `self::driverCode($e)` —
 * is not a hit, and neither is `$e::class` or `$e->getCode()`.
 *
 * @return list<string>
 */
$throwableVariables = static function (string $file): array {
    $tokens = PhpToken::tokenize(file_get_contents($file));
    $names = [];

    $significant = static function (array $tokens, int $from, int $direction = 1) {
        for ($i = $from; $i >= 0 && $i < count($tokens); $i += $direction) {
            if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $i;
            }
        }

        return null;
    };

    // `catch (SomeType|Other $var)` — the variable is the last T_VARIABLE
    // before the closing parenthesis of a `catch`.
    foreach ($tokens as $position => $token) {
        if (! $token->is(T_CATCH)) {
            continue;
        }

        for ($i = $position; $i < count($tokens); $i++) {
            if ($tokens[$i]->is(T_VARIABLE)) {
                $names[] = $tokens[$i]->text;
            }

            if ($tokens[$i]->text === ')') {
                break;
            }
        }
    }

    /*
     * Parameters typed `Throwable`: `f(Throwable $e)`, `f(?Throwable $e)`,
     * `f(Throwable|PDOException $e)`.
     *
     * Walked forward through the remainder of the type to the variable it
     * declares, IF it declares one — which is what makes `catch (Throwable)`
     * with no binding contribute nothing rather than mis-bind to whatever
     * follows the parenthesis.
     */
    foreach ($tokens as $position => $token) {
        if (! $token->is(T_STRING) || $token->text !== 'Throwable') {
            continue;
        }

        $before = $significant($tokens, $position - 1, -1);

        // `use Throwable;`, `instanceof Throwable`, `new Throwable`: none of
        // these introduce a variable holding one.
        if ($before !== null && $tokens[$before]->is([T_USE, T_INSTANCEOF, T_NEW, T_DOUBLE_COLON])) {
            continue;
        }

        for ($i = $position + 1; $i < count($tokens); $i++) {
            if ($tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STRING, T_NS_SEPARATOR, T_ELLIPSIS])
                || in_array($tokens[$i]->text, ['|', '&', '?'], true)) {
                continue;
            }

            if ($tokens[$i]->is(T_VARIABLE)) {
                $names[] = $tokens[$i]->text;
            }

            break;
        }
    }

    // One hop: `$other = $caught;`
    foreach ($tokens as $position => $token) {
        if (! $token->is(T_VARIABLE)) {
            continue;
        }

        $equals = $significant($tokens, $position + 1);
        $value = $equals === null ? null : $significant($tokens, $equals + 1);
        $end = $value === null ? null : $significant($tokens, $value + 1);

        if ($equals === null || $tokens[$equals]->text !== '=' || $value === null || $end === null) {
            continue;
        }

        if ($tokens[$value]->is(T_VARIABLE) && in_array($tokens[$value]->text, $names, true)
            && $tokens[$end]->text === ';') {
            $names[] = $token->text;
        }
    }

    return array_values(array_unique($names));
};

/**
 * The lines on which one of `$names` is turned into a string, or handed off
 * whole for something else to turn into one.
 *
 * Three shapes, all read as code:
 *
 *  - `(string) $e` — a T_STRING_CAST in front of the variable. Restricted to
 *    known throwable variables on purpose: `(string) $note['field']` is all
 *    over this package and is not a leak.
 *  - `$e->__toString()` / `$e->getTraceAsString()` — handled by $callsTo.
 *  - `['exception' => $e]` — the variable used as a COMPLETE expression, so the
 *    next significant token closes it (`,`, `]`, `)`). `$e::class` and
 *    `$e->getCode()` continue instead, and are not hits.
 *
 * @param  list<string>  $names
 * @return list<int>
 */
$stringificationsOf = static function (string $file, array $names): array {
    if ($names === []) {
        return [];
    }

    $tokens = PhpToken::tokenize(file_get_contents($file));
    $lines = [];

    $next = static function (array $tokens, int $from): ?int {
        for ($i = $from; $i < count($tokens); $i++) {
            if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $i;
            }
        }

        return null;
    };

    $previous = static function (array $tokens, int $from): ?int {
        for ($i = $from; $i >= 0; $i--) {
            if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                return $i;
            }
        }

        return null;
    };

    foreach ($tokens as $position => $token) {
        if (! $token->is(T_VARIABLE) || ! in_array($token->text, $names, true)) {
            continue;
        }

        $before = $previous($tokens, $position - 1);
        $after = $next($tokens, $position + 1);

        if ($before !== null && $tokens[$before]->is(T_STRING_CAST)) {
            $lines[] = $token->line;

            continue;
        }

        // Handed off whole as an array value or a call argument.
        if ($before !== null && $after !== null
            && $tokens[$before]->is(T_DOUBLE_ARROW)
            && in_array($tokens[$after]->text, [',', ']', ')'], true)) {
            $lines[] = $token->line;
        }
    }

    return $lines;
};

/**
 * Everything this test reads: the files scanned whole, plus the files scanned
 * only between the line ranges $boundedSources() declares.
 *
 * @return list<string>
 */
$allSources = static function () use ($csvImportSources, $boundedSources): array {
    $files = [...$csvImportSources(), ...array_keys($boundedSources())];

    sort($files);

    return $files;
};

$relative = static fn (string $file): string => str_replace(dirname(__DIR__, 2).'/', '', $file);

it('never puts an exception message into a log or a response body', function () use ($allSources, $inScope, $callsTo, $relative) {
    $files = $allSources();

    expect($files)->not->toBeEmpty('No sources were found — the glob path is wrong.');

    /*
     * recordDiagnostics() IS the sanctioned sink: it is off unless
     * LOG_CSV_IMPORT_DIAGNOSTICS is set, and it writes its own file with its own
     * permissions and retention. It is exempt, and the exemption is bounded BY
     * REFLECTION to that one method's line range so it cannot spread to the rest
     * of the class. If the method is ever renamed or moved this throws, which is
     * the right way to find out.
     *
     * TWO of them now, and only one does any reading. ErrorDigest's is the real
     * sink; ImportErrorDigest's is the importer's facade over it and calls no
     * message at all today. Its entry is kept anyway, because the tripwire in
     * the paragraph above is the point: deleting the entry of a delegating
     * method is how the exemption would come to be re-added by hand, unbounded,
     * the first time somebody inlines the delegation.
     *
     * @var list<ReflectionMethod>
     */
    $sinks = [
        new ReflectionMethod(ErrorDigest::class, 'recordDiagnostics'),
        new ReflectionMethod(ImportErrorDigest::class, 'recordDiagnostics'),
    ];

    $sanctioned = static function (string $file, int $line) use ($sinks): bool {
        foreach ($sinks as $sink) {
            if ($file === $sink->getFileName()
                && $line >= $sink->getStartLine()
                && $line <= $sink->getEndLine()) {
                return true;
            }
        }

        return false;
    };

    /*
     * EMPTY, and kept rather than deleted.
     *
     * It held `app/Console/Commands/RedactCsvImportRows.php` while that command
     * was being converted in parallel — LISTED rather than excluded from the
     * sweep, so that no NEW site could appear anywhere, including elsewhere in
     * that same file, while it was outstanding. The conversion has landed: both
     * sites now go through ImportErrorDigest::context(), and the console line
     * through ImportErrorDigest::driverCode(), so the entry was removed by the
     * engineer who owns that file, exactly as the assertion at the bottom
     * demands. See CsvImportRowRetentionTest::
     * test_a_failed_redaction_never_prints_or_logs_the_query_that_failed(),
     * which pins the behaviour rather than the token.
     *
     * The mechanism stays because the next conversion will want it, and because
     * an empty list is the only state in which this test says what it means: no
     * file on the CSV-import path may read an exception's own message.
     *
     * @var list<string>
     */
    $inFlight = [];

    $offending = [];
    $stillDirty = [];

    foreach ($files as $file) {
        $hits = [];

        /*
         * `__toString` and `getTraceAsString` alongside `getMessage`, because
         * all three read the exception's own text and two of them read MORE of
         * it: a Throwable's string form is the message plus the full trace.
         */
        foreach (['getMessage', '__toString', 'getTraceAsString'] as $reader) {
            foreach ($callsTo($file, $reader) as $line) {
                if (! $inScope($file, $line) || $sanctioned($file, $line)) {
                    continue;
                }

                $hits[] = $relative($file).':'.$line.' ('.$reader.')';
            }
        }

        sort($hits);

        if ($hits === []) {
            continue;
        }

        if (in_array($relative($file), $inFlight, true)) {
            $stillDirty[] = $relative($file);

            continue;
        }

        $offending = [...$offending, ...$hits];
    }

    if ($offending !== []) {
        $this->fail(
            "An exception's own text is being read where it may not be:\n\n  "
            .implode("\n  ", $offending)
            ."\n\nA QueryException's message is the failing SQL with the bindings substituted in, so on this "
            ."feature that string is a member's whole record — and everywhere it is logged is the shared "
            ."`single` channel, which never rotates and is world-readable.\n\nUse "
            .'ErrorDigest::context($e) in the log context (ImportErrorDigest::context($e) on the import path, '
            .'which forwards to it), emitting the exception class, the SQLSTATE and the driver code; and '
            .'recordDiagnostics($e, [...]) if the full text is genuinely needed, which routes it to the opt-in '
            .'restricted channel instead.'
        );
    }

    expect($offending)->toBe([]);

    expect(array_values(array_diff($inFlight, $stillDirty)))->toBe(
        [],
        'A file on the in-flight list no longer reads an exception message, which is the parallel fix landing '
        .'rather than anything breaking. Delete its entry from $inFlight above — an exemption that has outlived '
        .'its reason is an exemption the next leak hides behind.'
    );
});

it('never reports one of these exceptions to the default channel', function () use ($allSources, $inScope, $callsToFunction, $relative) {
    /*
     * report() is the same leak by another route. Laravel's default handler
     * logs `$e->getMessage()` to the DEFAULT channel — `single` again — so
     * gating report() behind the diagnostics flag would not help: the flag
     * decides whether the text is written, not where. recordDiagnostics() is
     * the only call that changes the destination.
     *
     * EMPTY, and kept rather than deleted.
     *
     * It held `app/Services/CsvImport/ErrorReportBuilder.php` — LISTED rather
     * than excluded, so no NEW site could appear anywhere while that one was
     * outstanding. It has been converted, and the entry removed with it,
     * exactly as the assertion at the bottom demands.
     *
     * That site was worth closing rather than deferring for a reason worth
     * keeping: it is the catch around a GENERATOR that streams staged member
     * rows, so unlike every other catch on this path — all of which handle
     * run-level metadata — the query it reports on is a query about a person.
     *
     * @var list<string>
     */
    $known = [];

    $offending = [];
    $seen = [];

    foreach ($allSources() as $file) {
        $lines = array_values(array_filter(
            $callsToFunction($file, 'report'),
            static fn (int $line): bool => $inScope($file, $line),
        ));

        if ($lines === []) {
            continue;
        }

        if (in_array($relative($file), $known, true)) {
            $seen[] = $relative($file);

            continue;
        }

        foreach ($lines as $line) {
            $offending[] = $relative($file).':'.$line;
        }
    }

    if ($offending !== []) {
        $this->fail(
            "report() sends an exception's message to the DEFAULT log channel:\n\n  "
            .implode("\n  ", $offending)
            ."\n\nOn this feature that message is a member's record. Use "
            .'ImportErrorDigest::recordDiagnostics($e, [...]), which carries the same text — with the file and '
            .'line — to the dedicated `csv-import` channel that is off by default.'
        );
    }

    expect(array_values(array_diff($known, $seen)))->toBe(
        [],
        'A file on the report() allowlist no longer calls report(). Delete its entry: an exemption that has '
        .'outlived its reason is an exemption the next leak hides behind.'
    );
});

it('never stringifies one of these exceptions into a log context', function () use ($allSources, $inScope, $throwableVariables, $stringificationsOf, $relative) {
    /*
     * The third and fourth spellings of the same sink, and the reason they are
     * here is how the third one was found: BY HAND, during a review sweep, not
     * by this test. Anything a person can find by reading can come back the
     * moment nobody is reading.
     *
     * `(string) $e` and `['exception' => $e]` both end as the exception's own
     * text in a log line — the first directly, the second once Monolog
     * stringifies the object it was handed. On this path that text is a
     * QueryException's SQL with the bindings substituted in, which is a
     * member's whole record. And the cast form is worse than the two spellings
     * already pinned, because a Throwable's string form carries the full stack
     * trace as well as the message.
     *
     * NO ALLOWLIST. Both were clean when this was written — nothing to grandfather
     * in, so nothing to prune later.
     */
    $files = $allSources();

    expect($files)->not->toBeEmpty('No sources were found — the glob path is wrong.');

    $offending = [];
    $scannedFiles = [];

    foreach ($files as $file) {
        $names = $throwableVariables($file);

        if ($names === []) {
            continue;
        }

        $scannedFiles[] = $file;

        foreach ($stringificationsOf($file, $names) as $line) {
            if (! $inScope($file, $line)) {
                continue;
            }

            $offending[] = $relative($file).':'.$line;
        }
    }

    /*
     * The scan has to have had something to look at. If the discovery above
     * ever stops finding variables — a tokeniser change, a refactor to
     * `catch (Throwable)` with no binding everywhere — this test would go green
     * by looking at nothing, which is the failure mode an arch test can least
     * afford.
     */
    expect($scannedFiles)->not->toBeEmpty('No file yielded a throwable variable, so this test scanned nothing.');

    /*
     * ...and a count is not enough, which is the specific way this test was
     * already blind.
     *
     * ErrorDigest.php's only catch is `} catch (Throwable) {` with no binding.
     * While discovery read catch bindings alone, that file yielded no names and
     * was skipped whole — the one file holding a deliberate getMessage() call,
     * unscanned — and `$scanned > 0` never noticed, because the importer's many
     * catch blocks kept the number healthy. A tripwire that counts cannot see
     * the absence of a particular file, so this one names it, by reflection so
     * that moving the class fails loudly rather than silently dropping it.
     */
    $this->assertContains(
        (new ReflectionClass(ErrorDigest::class))->getFileName(),
        $scannedFiles,
        'The class holding the application\'s one sanctioned getMessage() yielded no throwable variable, so this '
        .'test skipped it entirely. That is how it was blind before: a file whose only catch has no binding '
        .'disappears from the scan, and a count-based tripwire cannot tell. Check $throwableVariables().'
    );

    if ($offending !== []) {
        $this->fail(
            "An exception object is being stringified into a log or a response:\n\n  "
            .implode("\n  ", $offending)
            ."\n\nA Throwable's string form is its message AND its full stack trace, and on this feature the "
            ."message is the failing SQL with the bindings substituted in — a member's whole record.\n\nUse "
            .'ImportErrorDigest::context($e), which emits the exception CLASS, the SQLSTATE and the driver code; '
            .'and ImportErrorDigest::recordDiagnostics($e, [...]) if the full text is genuinely needed, which '
            .'routes it to the opt-in `csv-import` channel instead.'
        );
    }

    expect($offending)->toBe([]);
});

it('claims every bulk borrower endpoint there is', function () use ($boundedSources) {
    /*
     * The bounded scope above names two methods. This is what stops that being
     * a list somebody forgets.
     *
     * The reason BorrowerController is scanned at all is that its only previous
     * guard was behavioural, and a behavioural guard covers the methods
     * somebody wrote a test for: a THIRD bulk method could ship with
     * `$e->getMessage()` in its response and nothing would say a word.
     * Reflection-bounded ranges fix the coverage of these two and reproduce
     * exactly that gap for the next one — so the gap is closed here instead,
     * by asserting the list is complete rather than merely correct.
     *
     * Deliberately NOT a glob of the controller. Adding a bulk endpoint should
     * cost one line in this array plus whatever it takes to make the scan pass;
     * it should not silently enrol every unrelated method in the file.
     */
    $declared = [];

    foreach ((new ReflectionClass(BorrowerController::class))->getMethods() as $method) {
        if ($method->getDeclaringClass()->getName() === BorrowerController::class
            && str_starts_with($method->getName(), 'bulk')) {
            $declared[] = $method->getName();
        }
    }

    sort($declared);

    expect($declared)->toBe(
        ['bulkDeactivate', 'bulkDestroy'],
        'BorrowerController has gained or lost a bulk endpoint. Every one of them returns a per-id `failed` '
        .'array straight to the caller, which is where this whole rule was re-learnt — an exception message in '
        .'that array is a member record in an HTTP response body. Add it to $boundedSources() above so its '
        .'lines are actually scanned, then update this list.'
    );

    // And the ranges really did resolve to that file, rather than to nothing.
    expect($boundedSources())->toHaveCount(1);
});
