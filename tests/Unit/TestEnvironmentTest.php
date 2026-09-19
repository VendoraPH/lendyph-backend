<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the test harness itself.
 *
 * Both assertions here have already failed in production of this suite once:
 * the parallel run died on a 128M default with no php.ini anywhere to raise it,
 * and a Pest-style file without `uses(TestCase::class)` silently ran against a
 * bare PHPUnit case with no app and no database.
 *
 * Deliberately extends PHPUnit's TestCase rather than Tests\TestCase: this must
 * describe the worker process as it actually is, without the framework bootstrap
 * that the thing under test is supposed to provide.
 */
class TestEnvironmentTest extends TestCase
{
    /**
     * Runs inside whichever process actually executes the tests, which is never
     * the one the `php` command line touched: artisan test delegates to a child
     * in serial mode and to ParaTest workers in parallel mode. phpunit.xml's
     * <ini> block is the only thing that reaches either.
     */
    public function test_the_worker_has_enough_memory_to_finish_the_suite(): void
    {
        $limit = ini_get('memory_limit');

        $this->assertNotSame('-1', $limit, 'memory_limit is unlimited; phpunit.xml should pin it.');

        $bytes = $this->toBytes($limit);

        $this->assertGreaterThanOrEqual(
            256 * 1024 * 1024,
            $bytes,
            "This process has memory_limit={$limit}. The suite OOMs below ~256M. "
            .'Raise it in the <php> block of phpunit.xml. A `php -d` flag will not '
            .'work: artisan test hands the run to a child process, so the flag stays '
            .'with the parent in serial and parallel alike.'
        );
    }

    private function toBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
