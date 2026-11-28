<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CIC captures: every reportable subject's and contract's CSDF values, frozen
 * at a reference date (the month-end schedule, a staging test capture, or a
 * correction), with a checksum over its records. Files and corrections are
 * built only from a complete capture, which never changes again.
 *
 * One complete month-end capture per reference date, held by the database
 * too: `month_end_key` is the reference date only on a complete month_end row
 * (NULL otherwise) and unique. A failed or running capture doesn't count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cic_captures', function (Blueprint $table) {
            $table->id();
            $table->date('reference_date');
            $table->enum('kind', ['month_end', 'test', 'correction']);
            $table->enum('status', ['capturing', 'complete', 'failed'])->default('capturing');
            $table->date('month_end_key')->nullable()->storedAs("case when `kind` = 'month_end' and `status` = 'complete' then `reference_date` else null end");
            $table->unsignedInteger('subject_count')->default(0);
            $table->unsignedInteger('contract_count')->default(0);
            $table->unsignedInteger('event_count')->default(0);
            $table->char('checksum', 64)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique('month_end_key');
            $table->index(['reference_date', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cic_captures');
    }
};
