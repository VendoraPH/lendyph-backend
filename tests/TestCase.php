<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;

abstract class TestCase extends BaseTestCase
{
    /**
     * Worker databases already ensured to exist, so CREATE DATABASE only runs
     * once per worker process rather than on every test.
     *
     * @var array<string, true>
     */
    private static array $ensuredWorkerDatabases = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useParallelWorkerDatabase();
    }

    /**
     * Point this worker at its own database when running under
     * `php artisan test --parallel`.
     *
     * This suite resets state with a manual `migrate:fresh` in SetupLendyPH
     * rather than the RefreshDatabase trait, so Laravel's built-in per-worker
     * database isolation (which is gated on those traits) never kicks in. Without
     * this, every parallel worker would share the single `lendyph_testing`
     * database and their concurrent `migrate:fresh` calls would corrupt each
     * other. A normal sequential run has no token, so this is a no-op there.
     */
    private function useParallelWorkerDatabase(): void
    {
        $token = ParallelTesting::token();

        if (empty($token)) {
            return;
        }

        $connection = config('database.default');
        $baseDatabase = config("database.connections.{$connection}.database");
        $workerDatabase = "{$baseDatabase}_test_{$token}";

        // We're still connected to the (existing) base database here, so we can
        // create the worker database from this connection before switching to it.
        if (! isset(self::$ensuredWorkerDatabases[$workerDatabase])) {
            DB::connection($connection)->statement(
                "create database if not exists `{$workerDatabase}` "
                .'character set utf8mb4 collate utf8mb4_unicode_ci'
            );

            self::$ensuredWorkerDatabases[$workerDatabase] = true;
        }

        config()->set("database.connections.{$connection}.database", $workerDatabase);
        DB::purge($connection);
    }
}
