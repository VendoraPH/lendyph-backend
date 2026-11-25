<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reprint after a change (finish run round 3, R.A. 3765): one row each
     * time the disclosure statement or the promissory note is printed, next to
     * the `printed` audit row POST /loans/{loan}/print-log writes.
     *
     * - `document` is `disclosure` or `promissory_note`.
     * - `terms_fingerprint` is the SHA-256 (hex) of the terms the document
     *   stated when it was printed (LoanDocumentTerms), or null when the
     *   document couldn't be built then. Null never blocks a release.
     * - `terms_version` is LoanDocumentTerms::VERSION at the print. A print
     *   under another version never blocks.
     * - `printed_by` keeps its user, as audit rows do (restrict on delete).
     *
     * The index answers "the latest print of this document for this loan".
     * Listed in TimezoneShift::EXCLUDED_COLUMNS (added after the one-shot
     * shift).
     */
    public function up(): void
    {
        Schema::create('loan_document_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();
            $table->string('document', 32);
            $table->char('terms_fingerprint', 64)->nullable();
            $table->unsignedSmallInteger('terms_version');
            $table->foreignId('printed_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('printed_at');

            $table->index(['loan_id', 'document', 'id'], 'loan_document_prints_latest_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_document_prints');
    }
};
