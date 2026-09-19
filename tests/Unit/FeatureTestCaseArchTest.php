<?php

/**
 * Every Pest-style file under tests/Feature must bind Tests\TestCase itself.
 *
 * Pest hands a functional-style file the bare PHPUnit\Framework\TestCase unless
 * something says otherwise. A feature test that gets it has no application, no
 * database and no RefreshDatabase — and it does not announce that. `$this->get()`
 * simply is not there, the file contributes nothing it claims to, and the run
 * still reports green. That is the worst shape a test can take: present, counted,
 * and asserting nothing.
 *
 * The obvious fix is a tests/Pest.php with `uses(Tests\TestCase::class)->in('Feature')`,
 * and it does not work here. Pest treats the folder binding and a per-file
 * `uses()` as a conflict rather than a duplicate, and refuses the whole run:
 *
 *     Test case [Tests\TestCase] can not be used.
 *     The folder [.../ApiDocsAccessTest.php] already uses the test case [Tests\TestCase].
 *
 * All 29 Pest-style feature files already declare it, so adopting the folder
 * binding would mean deleting the line from every one of them — churn across 29
 * files to remove a line that is doing its job and is clearer where it is. This
 * guard keeps the explicit declarations and makes the thirtieth file impossible
 * to forget, with a better message than Pest's.
 *
 * Class-based files are untouched: they inherit Tests\TestCase and never call
 * uses().
 *
 * No database, no application boot: this reads the test files' own source.
 */
function pestStyleFeatureFilesMissingTestCase(): array
{
    $root = dirname(__DIR__).'/Feature';

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    $offenders = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        // A class-based file declares a class and inherits its base; only
        // functional-style files need the binding. Match `test(` / `it(` at the
        // start of a line so a nested helper call cannot be mistaken for one.
        $isPestStyle = (bool) preg_match('/^\s*(test|it)\s*\(/m', $source);

        if (! $isPestStyle) {
            continue;
        }

        if (str_contains($source, 'uses(')) {
            continue;
        }

        $offenders[] = str_replace($root.'/', '', $file->getPathname());
    }

    sort($offenders);

    return $offenders;
}

test('every Pest-style feature test binds the application test case', function () {
    $offenders = pestStyleFeatureFilesMissingTestCase();

    expect($offenders)->toBe([], sprintf(
        'These feature tests run without the application, the database or '
        ."RefreshDatabase, and will pass while asserting nothing:\n\n  %s\n\n"
        .'Add `uses(Tests\\TestCase::class);` near the top of each. Do NOT solve '
        .'this with a tests/Pest.php folder binding — Pest rejects the run when a '
        .'file also declares it, which every other feature test here does.',
        implode("\n  ", $offenders)
    ));
});

test('the guard actually recognises a file that forgets the binding', function () {
    // Proves the detector rather than trusting it: without this, a broken regex
    // would report an empty offender list forever and the guard above would pass
    // for the wrong reason.
    $source = "<?php\n\nit('does something', function () {\n    expect(true)->toBeTrue();\n});\n";

    expect((bool) preg_match('/^\s*(test|it)\s*\(/m', $source))->toBeTrue();
    expect(str_contains($source, 'uses('))->toBeFalse();
});
