<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One captured CSDF record (ID, CI, CN or NE): its values (field id → the
 * CSDF string, already formatted) and a checksum over them. Immutable once
 * its capture is complete (CicCaptureRecord). A capture that is still running
 * or failed takes its records with it if it is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cic_capture_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capture_id')->constrained('cic_captures')->cascadeOnDelete();
            $table->char('record_type', 2);
            $table->foreignId('cic_subject_id')->nullable()->constrained('cic_subjects')->restrictOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained('loans')->restrictOnDelete();
            $table->string('record_key', 38);
            $table->json('values');
            $table->char('checksum', 64);
            $table->timestamps();

            $table->unique(['capture_id', 'record_type', 'record_key']);
            $table->index(['capture_id', 'record_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cic_capture_records');
    }
};
