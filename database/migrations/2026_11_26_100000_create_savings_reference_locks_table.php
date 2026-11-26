<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finish run round 6 (money review): one lock row per method whose outside
 * references must not be reused (App\Services\Savings\DuplicateReference).
 *
 * The reuse check reads, then writes. Two writers on different accounts
 * (different account locks) could both read "unused" and both save the same
 * reference. DuplicateReference now takes this method's row `FOR UPDATE`
 * before it reads, so writers checking the same method wait for each other.
 *
 * Seeds bank_transfer, gcash, maya and savings (cash is exempt). No dates, so
 * nothing for TimezoneShift. Running it again changes nothing.
 */
return new class extends Migration
{
    private const METHODS = ['bank_transfer', 'gcash', 'maya', 'savings'];

    public function up(): void
    {
        if (! Schema::hasTable('savings_reference_locks')) {
            Schema::create('savings_reference_locks', function (Blueprint $table): void {
                $table->string('method', 24)->primary();
            });
        }

        DB::table('savings_reference_locks')->insertOrIgnore(array_map(fn (string $method) => ['method' => $method], self::METHODS));
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_reference_locks');
    }
};
