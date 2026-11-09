<?php

namespace Tests;

use Illuminate\Database\Connection;
use stdClass;

/**
 * A copy of the freshly migrated and seeded test database, so a test that opts
 * out of the per-test transaction can start from exactly that state without
 * paying for a real `migrate:fresh` every time.
 *
 * Why: every test that sets `$wrapsEachTestInTransaction = false` used to run
 * `migrate:fresh` with the seeder before it (about 4 s locally, 8 s on a
 * GitHub runner, for 150+ migrations). By 2026-11-09 the race and collision
 * suites had grown to 170+ such tests, about 60% of the whole suite's time.
 *
 * How it stays exact:
 *  - capture() runs straight after a real `migrate:fresh --seeder`. It copies
 *    every table's rows into a side database (`<test db>_seeded`) and keeps
 *    each table's CREATE TABLE text, CHECKSUM TABLE value, row count and
 *    AUTO_INCREMENT counter in memory.
 *  - restore() first compares the live schema with the captured one: the same
 *    tables, and the same CREATE TABLE text for each (columns, types, indexes,
 *    foreign keys, engine, collation). Any difference, such as a test that
 *    altered or dropped a table, and it refuses, so the caller runs the real
 *    `migrate:fresh` and captures again.
 *  - Otherwise it rewrites every table whose rows differ from the capture
 *    (checksum or row count), and puts every AUTO_INCREMENT counter back
 *    where the fresh seed left it, so ids restart exactly as after
 *    `migrate:fresh`.
 *
 * The state lives in static properties, so it belongs to one worker process
 * and one database and seeder, and a new process always starts with a real
 * migrate. The `*_seeded` copy is left behind after a run, like the per-worker
 * test databases themselves; the next capture replaces it.
 */
final class SeededBaseline
{
    /**
     * The captured tables, keyed by name, for the rebuild named in $key.
     *
     * @var array<string, array{create: string, checksum: ?int, rows: int, increment: ?int, columns: string}>|null
     */
    private static ?array $tables = null;

    /**
     * Which rebuild the capture came from: the database name plus the options
     * `migrate:fresh` ran with (the seeder above all).
     */
    private static ?string $key = null;

    /**
     * Copy the database as it stands now, straight after a fresh migrate and seed.
     *
     * @param  array<string, mixed>  $options  the options `migrate:fresh` ran with
     */
    public static function capture(Connection $connection, array $options): void
    {
        self::$tables = null;
        self::$key = null;

        $database = $connection->getDatabaseName();
        $copy = self::copyName($database);

        $connection->statement('DROP DATABASE IF EXISTS `'.$copy.'`');
        $connection->statement('CREATE DATABASE `'.$copy.'`');

        $tables = [];

        foreach (self::tableNames($connection) as $table) {
            $columns = self::insertableColumns($connection, $database, $table);

            $connection->statement('CREATE TABLE `'.$copy.'`.`'.$table.'` LIKE `'.$table.'`');
            $connection->statement('INSERT INTO `'.$copy.'`.`'.$table.'` ('.$columns.') SELECT '.$columns.' FROM `'.$table.'`');

            [$create, $increment] = self::createStatement($connection, $table);
            [$checksum, $rows] = self::contents($connection, $table);

            $tables[$table] = [
                'create' => $create,
                'checksum' => $checksum,
                'rows' => $rows,
                'increment' => $increment,
                'columns' => $columns,
            ];
        }

        self::$tables = $tables;
        self::$key = self::key($database, $options);
    }

    /**
     * Put the database back to the captured state.
     *
     * Returns false, having changed nothing, when there is no capture for this
     * database and these `migrate:fresh` options (a test class with its own
     * seeder gets a real rebuild), or the schema no longer matches; the caller
     * must then run a real `migrate:fresh` and capture() again.
     *
     * @param  array<string, mixed>  $options  the options `migrate:fresh` would run with
     */
    public static function restore(Connection $connection, array $options): bool
    {
        $database = $connection->getDatabaseName();

        if (self::$tables === null || self::$key !== self::key($database, $options)) {
            return false;
        }

        $live = self::tableNames($connection);
        $captured = array_keys(self::$tables);
        sort($live);
        sort($captured);

        if ($live !== $captured) {
            return false;
        }

        $increments = [];

        foreach (self::$tables as $table => $baseline) {
            [$create, $increment] = self::createStatement($connection, $table);

            if ($create !== $baseline['create']) {
                return false;
            }

            $increments[$table] = $increment;
        }

        $copy = self::copyName($database);

        $connection->statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach (self::$tables as $table => $baseline) {
                [$checksum, $rows] = self::contents($connection, $table);

                if ($checksum !== $baseline['checksum'] || $rows !== $baseline['rows']) {
                    $connection->statement('DELETE FROM `'.$table.'`');
                    $connection->statement('INSERT INTO `'.$table.'` ('.$baseline['columns'].') SELECT '.$baseline['columns'].' FROM `'.$copy.'`.`'.$table.'`');
                }

                if ($baseline['increment'] !== $increments[$table]) {
                    $connection->statement('ALTER TABLE `'.$table.'` AUTO_INCREMENT = '.($baseline['increment'] ?? 1));
                }
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function key(string $database, array $options): string
    {
        ksort($options);

        return $database.'|'.json_encode($options, JSON_THROW_ON_ERROR);
    }

    private static function copyName(string $database): string
    {
        return $database.'_seeded';
    }

    /**
     * @return list<string>
     */
    private static function tableNames(Connection $connection): array
    {
        return array_map(
            fn (stdClass $row): string => $row->name,
            $connection->select(
                "select table_name as name from information_schema.tables
                 where table_schema = database() and table_type = 'BASE TABLE'
                 order by table_name",
            ),
        );
    }

    /**
     * The table's columns that accept a value, quoted and comma separated.
     * Generated columns (VIRTUAL or STORED) are left out: MySQL refuses an
     * explicit value for one. A column whose DEFAULT is an expression, such as
     * `useCurrent()`, also reads DEFAULT_GENERATED in `extra` but takes its
     * stored value, so it is copied like any other.
     */
    private static function insertableColumns(Connection $connection, string $database, string $table): string
    {
        $columns = $connection->select(
            "select column_name as name from information_schema.columns
             where table_schema = ? and table_name = ? and generation_expression = ''
             order by ordinal_position",
            [$database, $table],
        );

        return implode(', ', array_map(fn (stdClass $column): string => '`'.$column->name.'`', $columns));
    }

    /**
     * The CREATE TABLE text without its AUTO_INCREMENT counter, and the counter.
     *
     * @return array{0: string, 1: ?int}
     */
    private static function createStatement(Connection $connection, string $table): array
    {
        $row = (array) $connection->selectOne('SHOW CREATE TABLE `'.$table.'`');
        $create = (string) $row['Create Table'];

        $increment = preg_match('/ AUTO_INCREMENT=(\d+)/', $create, $match) ? (int) $match[1] : null;

        return [preg_replace('/ AUTO_INCREMENT=\d+/', '', $create), $increment];
    }

    /**
     * @return array{0: ?int, 1: int}
     */
    private static function contents(Connection $connection, string $table): array
    {
        $checksum = $connection->selectOne('CHECKSUM TABLE `'.$table.'`')->Checksum;
        $rows = (int) $connection->selectOne('SELECT COUNT(*) AS n FROM `'.$table.'`')->n;

        return [$checksum === null ? null : (int) $checksum, $rows];
    }
}
