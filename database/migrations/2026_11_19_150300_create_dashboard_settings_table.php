<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 EB-C: the executive dashboard's alert thresholds, one company-wide
 * row (id 1), seeded with the approved defaults (decision 20, 2026-11-17).
 * Percentages and points are stored as written (two decimals, never
 * rounded), days as whole days and the control difference in pesos. The
 * evaluator that reads them is EB-D2's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_settings', function (Blueprint $table): void {
            $table->id();
            $table->decimal('par30_rise_points', 5, 2)->default(1);
            $table->unsignedSmallInteger('par30_compare_days')->default(30);
            $table->decimal('collection_efficiency_below', 5, 2)->default(90);
            $table->decimal('overdue_share_above', 5, 2)->default(5);
            $table->unsignedSmallInteger('approval_wait_days')->default(3);
            $table->unsignedSmallInteger('release_wait_days')->default(2);
            $table->decimal('control_difference_above', 15, 2)->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('dashboard_settings')->insert([
            'id' => 1,
            'par30_rise_points' => '1.00',
            'par30_compare_days' => 30,
            'collection_efficiency_below' => '90.00',
            'overdue_share_above' => '5.00',
            'approval_wait_days' => 3,
            'release_wait_days' => 2,
            'control_difference_above' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_settings');
    }
};
