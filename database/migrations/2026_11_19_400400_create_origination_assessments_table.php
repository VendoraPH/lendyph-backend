<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loan origination, slice LO-B4 (origination decisions 14 and 15, Phase 6
 * owner question 11):
 *
 * - `origination_assessments`: each run of an application's capacity
 *   assessment, and each change of the officer's notes, as an immutable
 *   revision numbered per application. The result, the provisional flag and
 *   the assessed request stay plain, for lists and the hand-off's checks; the
 *   figures, the verified amounts, the explanation and the notes are
 *   encrypted, as the draft is. Revisions cascade only from their
 *   application, for the retention erasure.
 * - `origination_settings.require_approved_member`: a loan isn't released
 *   while its member's registration isn't approved. On by default, so new
 *   releases follow it from the deploy; nothing already released changes.
 * - `origination_documents.id_details`: the ID type and number staff record
 *   when they verify an applicant's ID, encrypted. The hand-off copies the ID
 *   to the member as their valid ID with them.
 * - `credit_application_profiles.monthly_income`: the application's verified
 *   monthly income, as its assessment counted it, which the hand-off records
 *   and Credit Scoring reads for that loan instead of the member record's.
 *
 * New table and columns only; no data fix. Running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('origination_assessments')) {
            Schema::create('origination_assessments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('application_id')->constrained('origination_applications')->cascadeOnDelete();
                $table->unsignedInteger('version');
                $table->enum('kind', ['run', 'notes']);
                $table->foreignId('rule_revision_id')->constrained('origination_rule_revisions')->restrictOnDelete();
                $table->foreignId('borrower_id')->nullable()->constrained('borrowers')->restrictOnDelete();
                $table->foreignId('loan_product_id')->constrained('loan_products')->restrictOnDelete();
                $table->decimal('requested_amount', 15, 2);
                $table->unsignedSmallInteger('requested_term');
                $table->string('frequency', 30);
                $table->date('start_date');
                $table->enum('result', ['within_capacity', 'further_review', 'above_capacity', 'insufficient_information']);
                $table->boolean('provisional');
                $table->longText('figures');
                $table->longText('inputs');
                $table->text('thresholds');
                $table->text('checks');
                $table->longText('explanation');
                $table->longText('missing_information');
                $table->longText('conflicts');
                $table->text('instalment_basis')->nullable();
                $table->text('officer_findings')->nullable();
                $table->text('recommendations')->nullable();
                $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('created_at')->nullable();
                $table->unique(['application_id', 'version']);
                $table->index(['result', 'application_id']);
            });
        }

        if (! Schema::hasColumn('origination_settings', 'require_approved_member')) {
            Schema::table('origination_settings', function (Blueprint $table): void {
                $table->boolean('require_approved_member')->default(true)->after('default_branch_id');
            });
        }

        if (! Schema::hasColumn('origination_documents', 'id_details')) {
            Schema::table('origination_documents', function (Blueprint $table): void {
                $table->text('id_details')->nullable()->after('notes');
            });
        }

        if (! Schema::hasColumn('credit_application_profiles', 'monthly_income')) {
            Schema::table('credit_application_profiles', function (Blueprint $table): void {
                $table->decimal('monthly_income', 14, 2)->nullable()->after('loan_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('credit_application_profiles', 'monthly_income')) {
            Schema::table('credit_application_profiles', function (Blueprint $table): void {
                $table->dropColumn('monthly_income');
            });
        }

        if (Schema::hasColumn('origination_documents', 'id_details')) {
            Schema::table('origination_documents', function (Blueprint $table): void {
                $table->dropColumn('id_details');
            });
        }

        if (Schema::hasColumn('origination_settings', 'require_approved_member')) {
            Schema::table('origination_settings', function (Blueprint $table): void {
                $table->dropColumn('require_approved_member');
            });
        }

        Schema::dropIfExists('origination_assessments');
    }
};
