<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom compliance calendar items (gap round B6): deadlines the cooperative
 * tracks beyond the ten CDA report types (a permit renewal, a BIR filing),
 * per reporting year. The effective due date is the extension, else the
 * default. Shown on the calendar and reminded like the reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cda_compliance_requirements')) {
            return;
        }

        Schema::create('cda_compliance_requirements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->date('default_due_on');
            $table->date('extended_due_on')->nullable();
            $table->string('reference', 120)->nullable();
            $table->string('status', 16)->default('pending');
            $table->date('filed_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['year', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cda_compliance_requirements');
    }
};
