<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A CDA report's review chain (gap round B6): the `cda` chain's steps, copied
 * onto the report when it is submitted for review, one round per submit.
 * Each row keeps its step's name, role and optional status label as they
 * were at submit, so a later settings change never moves a report already in
 * review. `closed_at` marks a round that ended (returned, reopened).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cda_report_approval_steps')) {
            return;
        }

        Schema::create('cda_report_approval_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('cda_reports')->restrictOnDelete();
            $table->unsignedSmallInteger('round');
            $table->unsignedTinyInteger('step_order');
            $table->string('name', 100);
            $table->string('role', 100);
            $table->string('status_label', 100)->nullable();
            $table->string('status', 16);
            $table->foreignId('acted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('acted_at')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['report_id', 'round', 'step_order'], 'cda_report_approval_steps_order_unique');
            $table->index(['report_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cda_report_approval_steps');
    }
};
