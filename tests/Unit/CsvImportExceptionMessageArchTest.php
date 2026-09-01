<?php

use App\Services\CsvImport\ImportErrorDigest;

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
 * commands, which catch the same exceptions and log them to the same channel.
 *
 * AppServiceProvider's CSV-import listener is deliberately NOT scanned. It is a
 * shared file whose other owners have unrelated reasons to call getMessage(),
 * and an arch test that fails somebody for a line in another feature is an arch
 * test that gets deleted. That site is pinned behaviourally instead — see
 * CsvImportUploadApiTest::test_a_failing_storage_release_does_not_fail_the_run_it_was_tidying_up_after().
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

    // The console side, named rather than globbed: app/Console/Commands holds a
    // dozen commands that have nothing to do with importing, and sweeping the
    // directory would quietly claim ownership of all of them.
    foreach (['ProcessCsvImports.php', 'RedactCsvImportRows.php'] as $command) {
        $path = $root.'/app/Console/Commands/'.$command;

        if (is_file($path)) {
            $files[] = $path;
        }
    }

    sort($files);

    return $files;
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
 * Parameters typed `Throwable` are deliberately NOT included. The only ones on
 * this path are ImportErrorDigest's own, which is the sanctioned handler — the
 * class this test exists to push everything towards — and whose one permitted
 * read is already bounded by reflection below. A new leak does not arrive as a
 * parameter; it arrives in a catch block.
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

$relative = static fn (string $file): string => str_replace(dirname(__DIR__, 2).'/', '', $file);

it('never puts an exception message into a CSV-import log', function () use ($csvImportSources, $callsTo, $relative) {
    $files = $csvImportSources();

    expect($files)->not->toBeEmpty('No CSV-import sources were found — the glob path is wrong.');

    /*
     * ImportErrorDigest::recordDiagnostics() IS the sanctioned sink: it is off
     * unless LOG_CSV_IMPORT_DIAGNOSTICS is set, and it writes its own file with
     * its own permissions and retention. It is exempt, and the exemption is
     * bounded BY REFLECTION to that one method's line range so it cannot spread
     * to the rest of the class. If the method is ever renamed or moved this
     * throws, which is the right way to find out.
     */
    $sink = new ReflectionMethod(ImportErrorDigest::class, 'recordDiagnostics');
    $sinkFile = $sink->getFileName();

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
                if ($file === $sinkFile && $line >= $sink->getStartLine() && $line <= $sink->getEndLine()) {
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
            "An exception's own text is being read on the CSV-import path:\n\n  "
            .implode("\n  ", $offending)
            ."\n\nA QueryException's message is the failing SQL with the bindings substituted in, so on this "
            ."feature that string is a member's whole record — and everywhere it is logged is the shared "
            ."`single` channel, which never rotates and is world-readable.\n\nUse "
            .'ImportErrorDigest::context($e) in the log context, which emits the exception class, the SQLSTATE '
            .'and the driver code; and ImportErrorDigest::recordDiagnostics($e, [...]) if the full text is '
            .'genuinely needed, which routes it to the opt-in `csv-import` channel instead.'
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

it('never reports a CSV-import exception to the default channel', function () use ($csvImportSources, $callsToFunction, $relative) {
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

    foreach ($csvImportSources() as $file) {
        $lines = $callsToFunction($file, 'report');

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

it('never stringifies a CSV-import exception into a log context', function () use ($csvImportSources, $throwableVariables, $stringificationsOf, $relative) {
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
    $files = $csvImportSources();

    expect($files)->not->toBeEmpty('No CSV-import sources were found — the glob path is wrong.');

    $offending = [];
    $scanned = 0;

    foreach ($files as $file) {
        $names = $throwableVariables($file);

        if ($names === []) {
            continue;
        }

        $scanned++;

        foreach ($stringificationsOf($file, $names) as $line) {
            $offending[] = $relative($file).':'.$line;
        }
    }

    /*
     * The scan has to have had something to look at. If the catch-clause
     * discovery ever stops finding variables — a tokeniser change, a refactor
     * to `catch (Throwable)` with no binding everywhere — this test would go
     * green by looking at nothing, which is the failure mode an arch test can
     * least afford.
     */
    expect($scanned)->toBeGreaterThan(0, 'No file yielded a caught-throwable variable, so this test scanned nothing.');

    if ($offending !== []) {
        $this->fail(
            "An exception object is being stringified into a CSV-import log:\n\n  "
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
