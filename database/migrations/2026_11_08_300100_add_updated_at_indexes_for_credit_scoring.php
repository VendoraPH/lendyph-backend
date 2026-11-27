<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `updated_at` indexes on the four tables the credit scoring minute job
 * watches (`credit-scoring:process-changes`, ChangeScanner). Each tick reads
 * the rows changed since its watermark, a range on `updated_at`; without an
 * index that is a full scan of repayments and schedules every minute.
 *
 * Indexes only, no data change. Each step is guarded on Schema::hasIndex(),
 * so the migration runs safely twice.
 */
return new class extends Migration
{
    private const INDEXES = [
        'repayments' => 'repayments_updated_at_index',
        'loans' => 'loans_updated_at_index',
        'amortization_schedules' => 'amortization_schedules_updated_at_index',
        'borrowers' => 'borrowers_updated_at_index',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $name) {
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index('updated_at', $name));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $name) {
            if (Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
            }
        }
    }
};
