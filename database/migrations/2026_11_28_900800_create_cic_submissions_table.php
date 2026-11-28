<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CIC submissions, the submission history (prompt §15): one file built from a
 * complete capture, standard (type 0) or a correction (type 1, naming the
 * submission it corrects and why), through generated, encrypted, submitted,
 * and CIC's accepted or rejected.
 *
 * The plain TXT and ZIP are never stored: they are rebuilt from the immutable
 * capture when encrypting, in a temporary directory deleted at once, and
 * `txt_sha256` proves the rebuild is identical. Only the `.zip.gpg` is kept, on
 * the private disk (`gpg_path`). Rows are never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cic_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capture_id')->constrained('cic_captures')->restrictOnDelete();
            $table->date('reference_date');
            $table->unsignedTinyInteger('submission_type');
            $table->foreignId('corrects_submission_id')->nullable()->constrained('cic_submissions')->restrictOnDelete();
            $table->string('correction_reason', 500)->nullable();
            $table->string('filename', 80);
            $table->unsignedInteger('record_count');
            $table->char('txt_sha256', 64);
            $table->char('zip_sha256', 64)->nullable();
            $table->char('gpg_sha256', 64)->nullable();
            $table->string('gpg_path', 255)->nullable();
            $table->string('key_fingerprint', 64)->nullable();
            $table->enum('status', ['generated', 'encrypted', 'submitted', 'accepted', 'rejected'])->default('generated');
            $table->string('comments', 100)->nullable();
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('generated_at');
            $table->foreignId('encrypted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('encrypted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('acknowledged_at')->nullable();
            $table->text('acknowledgement_notes')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamps();

            $table->index('filename');
            $table->index(['reference_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cic_submissions');
    }
};
