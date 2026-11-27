<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CDA Reports v1 (Phase 3, design §1): the fifteen CDA tables.
 *
 * - Money is `decimal(15,2)` pesos, computed in centavos by the services.
 * - Types and statuses are varchar checked against the models' constants,
 *   never ENUMs, so a new CDA form or status needs no ALTER.
 * - Foreign keys restrict deletes, except a report's values and snapshots,
 *   which belong to the report, and a training, which belongs to its officer.
 * - A report keeps the template it was created with (`template_id`), so a
 *   newer template version never changes an existing report.
 *
 * Seeds the single settings row (annual reports 120 days after Dec 31,
 * mediation 30 days after each half, reminders at 30, 15, 7 and 3 days, and
 * the asset size bounds: micro up to ₱3,000,000.00, small up to
 * ₱15,000,000.00, medium up to ₱100,000,000.00, large above) and an empty
 * regulatory profile. Only new tables; no existing row changes.
 * Each create is skipped when its table exists, so running it again changes
 * nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->create('cda_regulatory_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('previous_name', 255)->nullable();
            $table->string('cda_registration_no', 60)->nullable();
            $table->date('cda_registration_date')->nullable();
            $table->string('cda_region', 80)->nullable();
            $table->string('cda_extension_office', 120)->nullable();
            $table->string('cooperative_type', 60)->nullable();
            $table->string('cooperative_category', 24)->nullable();
            $table->string('asset_class', 24)->nullable();
            $table->string('asset_class_source', 12)->default('computed');
            $table->string('tin', 20)->nullable();
            $table->string('cte_status', 12)->default('none');
            $table->string('cte_number', 60)->nullable();
            $table->date('cte_issued_on')->nullable();
            $table->date('cte_expires_on')->nullable();
            $table->string('address_line', 255)->nullable();
            $table->string('address_barangay', 120)->nullable();
            $table->string('address_city', 120)->nullable();
            $table->string('address_province', 120)->nullable();
            $table->string('address_region', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('primary_activity', 120)->nullable();
            $table->string('sector', 120)->nullable();
            $table->string('subsector', 120)->nullable();
            $table->unsignedSmallInteger('satellite_offices')->nullable();
            $table->unsignedBigInteger('authorized_rep_officer_id')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        $this->create('cda_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('annual_deadline_days')->default(120);
            $table->unsignedSmallInteger('mediation_deadline_days')->default(30);
            $table->json('reminder_days')->nullable();
            $table->json('required_trainings')->nullable();
            $table->json('asset_class_thresholds')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        $this->create('cda_report_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('report_type', 32);
            $table->string('version', 20);
            $table->string('name', 160);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->json('cooperative_types')->nullable();
            $table->json('asset_classes')->nullable();
            $table->json('definition');
            $table->foreignId('excel_template_document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->string('status', 12)->default('draft');
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['report_type', 'version']);
            $table->index(['report_type', 'status', 'effective_from']);
        });

        $this->create('cda_reporting_periods', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('default_deadline');
            $table->date('extended_deadline')->nullable();
            $table->string('extension_reference', 120)->nullable();
            $table->json('year_end_capture')->nullable();
            $table->timestamp('year_end_captured_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        $this->create('cda_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('period_id')->constrained('cda_reporting_periods')->restrictOnDelete();
            $table->string('report_type', 32);
            $table->string('part', 8)->default('annual');
            $table->foreignId('template_id')->nullable()->constrained('cda_report_templates')->restrictOnDelete();
            $table->string('status', 24)->default('draft');
            $table->string('sub_status', 32)->nullable();
            $table->boolean('applicable')->default(true);
            $table->string('not_applicable_reason', 255)->nullable();
            $table->date('default_due_on');
            $table->date('due_override_on')->nullable();
            $table->string('due_override_reference', 120)->nullable();
            $table->unsignedTinyInteger('readiness')->default(0);
            $table->json('readiness_breakdown')->nullable();
            $table->json('issues')->nullable();
            $table->unsignedInteger('critical_count')->default(0);
            $table->unsignedInteger('warning_count')->default(0);
            $table->timestamp('stale_at')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('submitted_for_review_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_for_review_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->unsignedBigInteger('current_snapshot_id')->nullable();
            $table->timestamps();
            $table->unique(['period_id', 'report_type', 'part']);
            $table->index('status');
        });

        $this->create('cda_report_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('cda_reports')->cascadeOnDelete();
            $table->string('field_key', 160);
            $table->json('value')->nullable();
            $table->string('source_key', 80)->nullable();
            $table->string('source_label', 255)->nullable();
            $table->json('source_params')->nullable();
            $table->json('source_value')->nullable();
            $table->boolean('is_override')->default(false);
            $table->string('override_reason', 500)->nullable();
            $table->text('remarks')->nullable();
            $table->json('evidence')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('refreshed_at')->nullable();
            $table->timestamps();
            $table->unique(['report_id', 'field_key']);
        });

        $this->create('cda_report_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('cda_reports')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('template_id')->nullable()->constrained('cda_report_templates')->restrictOnDelete();
            $table->longText('payload');
            $table->char('checksum', 64);
            $table->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reopen_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['report_id', 'version']);
        });

        $this->create('cda_submissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained('cda_reports')->restrictOnDelete();
            $table->foreignId('snapshot_id')->nullable()->constrained('cda_report_snapshots')->restrictOnDelete();
            $table->string('method', 16);
            $table->date('submitted_on');
            $table->string('submitted_by_name', 160)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cda_reference_no', 80)->nullable();
            $table->string('acknowledgment_no', 80)->nullable();
            $table->string('status', 24)->default('submitted');
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index('report_id');
        });

        $this->create('cda_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('period_id')->nullable()->constrained('cda_reporting_periods')->restrictOnDelete();
            $table->string('category', 40);
            $table->foreignId('report_id')->nullable()->constrained('cda_reports')->restrictOnDelete();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('status', 12)->default('active');
            $table->string('withdraw_reason', 500)->nullable();
            $table->date('expires_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['period_id', 'category']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('expires_on');
        });

        $this->create('cda_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cda_document_id')->constrained('cda_documents')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('version_label', 60)->nullable();
            $table->string('final_key', 80)->nullable()->unique();
            $table->string('origin', 12)->default('uploaded');
            $table->string('format', 12)->nullable();
            $table->boolean('is_draft')->default(false);
            $table->foreignId('snapshot_id')->nullable()->constrained('cda_report_snapshots')->restrictOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->char('sha256', 64)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['cda_document_id', 'version']);
        });

        $this->create('cda_officers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('borrower_id')->nullable()->constrained('borrowers')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('name', 160)->nullable();
            $table->string('sex', 8)->nullable();
            $table->string('position', 60);
            $table->string('committee', 80)->nullable();
            $table->string('position_title', 120)->nullable();
            $table->date('term_start');
            $table->date('term_end')->nullable();
            $table->date('election_date')->nullable();
            $table->string('status', 12)->default('active');
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['status', 'term_end']);
            $table->index('borrower_id');
        });

        $this->create('cda_officer_trainings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('officer_id')->constrained('cda_officers')->cascadeOnDelete();
            $table->string('title', 255);
            $table->string('requirement_key', 80)->nullable();
            $table->string('training_type', 40)->nullable();
            $table->boolean('is_mandatory')->default(false);
            $table->string('provider', 160)->nullable();
            $table->string('provider_accreditation_no', 80)->nullable();
            $table->date('started_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->decimal('hours', 6, 2)->default(0);
            $table->string('certificate_number', 80)->nullable();
            $table->string('status', 16)->default('completed');
            $table->date('expires_on')->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['officer_id', 'completed_on']);
        });

        $this->create('cda_social_programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('period_id')->constrained('cda_reporting_periods')->restrictOnDelete();
            $table->string('name', 255);
            $table->string('category', 40);
            $table->text('description')->nullable();
            $table->text('objective')->nullable();
            $table->date('held_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->string('location', 255)->nullable();
            $table->unsignedInteger('target_beneficiaries')->nullable();
            $table->unsignedInteger('actual_beneficiaries')->nullable();
            $table->decimal('budget', 15, 2)->nullable();
            $table->decimal('actual_expense', 15, 2)->default(0);
            $table->string('funding_source', 16)->nullable();
            $table->decimal('cdf_amount', 15, 2)->default(0);
            $table->string('status', 16)->default('completed');
            $table->foreignId('responsible_officer_id')->nullable()->constrained('cda_officers')->restrictOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index(['period_id', 'category']);
        });

        $this->create('cda_mediation_cases', function (Blueprint $table): void {
            $table->id();
            $table->string('case_number', 20)->unique();
            $table->date('filed_on');
            $table->text('complainant')->nullable();
            $table->text('respondent')->nullable();
            $table->string('nature', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('mediator', 160)->nullable();
            $table->date('mediation_started_on')->nullable();
            $table->unsignedSmallInteger('meetings_count')->default(0);
            $table->text('resolution')->nullable();
            $table->date('resolved_on')->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('filed_on');
            $table->index('resolved_on');
        });

        $this->create('cda_notices_sent', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('kind', 40);
            $table->string('dedupe_key', 120);
            $table->timestamp('sent_at')->nullable();
            $table->unique(['subject_type', 'subject_id', 'kind', 'dedupe_key'], 'cda_notices_sent_dedupe_unique');
        });

        if (! DB::table('cda_settings')->exists()) {
            DB::table('cda_settings')->insert([
                'annual_deadline_days' => 120,
                'mediation_deadline_days' => 30,
                'reminder_days' => json_encode([30, 15, 7, 3]),
                'required_trainings' => json_encode((object) []),
                'asset_class_thresholds' => json_encode(['micro' => '3000000.00', 'small' => '15000000.00', 'medium' => '100000000.00']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! DB::table('cda_regulatory_profiles')->exists()) {
            DB::table('cda_regulatory_profiles')->insert(['created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Refused once any report has been created: the reports are records the
     * cooperative files with CDA.
     */
    public function down(): void
    {
        if (Schema::hasTable('cda_reports') && DB::table('cda_reports')->exists()) {
            return;
        }

        Schema::disableForeignKeyConstraints();

        foreach ([
            'cda_notices_sent', 'cda_mediation_cases', 'cda_social_programs', 'cda_officer_trainings', 'cda_officers',
            'cda_document_versions', 'cda_documents', 'cda_submissions', 'cda_report_snapshots', 'cda_report_values',
            'cda_reports', 'cda_reporting_periods', 'cda_report_templates', 'cda_settings', 'cda_regulatory_profiles',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }

    private function create(string $name, Closure $columns): void
    {
        if (! Schema::hasTable($name)) {
            Schema::create($name, $columns);
        }
    }
};
