<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loan origination (Phase 6, slice LO-B1; notes/phase6-run/
 * analysis-loan-origination.md §4): the tables every later slice builds on.
 *
 * - Statuses are MySQL ENUMs, as the loans' are (audit §5 B1).
 * - The draft, mobile and email are stored with Laravel's `encrypted` cast,
 *   so these columns are TEXT. The list columns (name, product, amount,
 *   status, dates) stay plain, and the mobile is also kept as a keyed hash
 *   (OriginationApplication::mobileHash()).
 * - `open_mobile_hash` is a stored generated column, unique: the mobile's hash
 *   while the application is a draft or incomplete, else NULL. So a mobile has
 *   at most one open draft (decision 3).
 * - Notices, consents, notes, status history and rule revisions are
 *   append-only in their models; their foreign keys cascade only from the
 *   application, for the retention erasure (decision 10).
 *
 * Seeds the single settings row with online applications off and rule
 * revision 1 with the standard limits (decision 15). No existing row
 * changes. Each create is skipped when its table exists, so running it again
 * changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->create('origination_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('online_applications_enabled')->default(false);
            $table->foreignId('default_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('reference_prefix', 5)->default('OA');
            $table->unsignedSmallInteger('draft_expiration_days')->default(30);
            $table->unsignedSmallInteger('estimated_minutes')->nullable()->default(15);
            $table->json('accepted_file_types');
            $table->unsignedTinyInteger('max_upload_mb')->default(10);
            $table->unsignedSmallInteger('retention_days')->default(365);
            $table->json('document_types');
            $table->json('notifications');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        $this->create('origination_rule_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->json('rules');
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        $this->create('origination_product_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_product_id')->unique()->constrained('loan_products')->cascadeOnDelete();
            $table->boolean('online')->default(false);
            $table->json('required_fields');
            $table->text('eligibility')->nullable();
            $table->json('required_documents');
            $table->text('instructions')->nullable();
            $table->timestamps();
        });

        $this->create('origination_notices', function (Blueprint $table): void {
            $table->id();
            $table->enum('type', ['privacy_notice', 'terms', 'declaration', 'credit_authorization']);
            $table->string('version', 20);
            $table->string('title', 150)->nullable();
            $table->longText('body');
            $table->char('body_hash', 64);
            $table->boolean('is_final')->default(false);
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at');
            $table->timestamp('created_at')->nullable();
            $table->unique(['type', 'version']);
        });

        $this->create('origination_links', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('token', 64)->unique();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->boolean('is_test')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->string('display_name', 100)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_email', 150)->nullable();
            $table->unsignedInteger('visits')->default(0);
            $table->unsignedInteger('started')->default(0);
            $table->unsignedInteger('submitted')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'active']);
        });

        $this->create('origination_link_products', function (Blueprint $table): void {
            $table->foreignId('link_id')->constrained('origination_links')->cascadeOnDelete();
            $table->foreignId('loan_product_id')->constrained('loan_products')->cascadeOnDelete();
            $table->primary(['link_id', 'loan_product_id']);
        });

        $this->create('origination_applications', function (Blueprint $table): void {
            $table->id();
            $table->string('reference_number', 30)->nullable()->unique();
            $table->enum('status', [
                'draft', 'incomplete', 'submitted', 'document_verification', 'additional_info_required', 'under_assessment',
                'pending_approval', 'approved', 'rejected', 'withdrawn', 'converted', 'expired',
            ])->default('draft');
            $table->foreignId('link_id')->nullable()->constrained('origination_links')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('loan_product_id')->nullable()->constrained('loan_products')->restrictOnDelete();
            $table->decimal('requested_amount', 15, 2)->nullable();
            $table->unsignedSmallInteger('requested_term')->nullable();
            $table->string('applicant_name', 200)->nullable();
            $table->text('mobile')->nullable();
            $table->char('mobile_hash', 64)->nullable();
            $table->text('email')->nullable();
            $table->longText('draft')->nullable();
            $table->string('last_completed_step', 20)->nullable();
            $table->unsignedTinyInteger('completion_percent')->default(0);
            $table->boolean('is_test')->default(false);
            $table->foreignId('assigned_officer_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('borrower_id')->nullable()->constrained('borrowers')->restrictOnDelete();
            $table->foreignId('loan_id')->nullable()->unique()->constrained('loans')->restrictOnDelete();
            $table->text('applicant_message')->nullable();
            $table->boolean('legal_hold')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_saved_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('erased_at')->nullable();
            $table->timestamps();
            $table->index('mobile_hash');
            $table->index(['branch_id', 'status']);
            $table->index(['status', 'last_saved_at']);
            $table->index('submitted_at');
        });

        if (! Schema::hasColumn('origination_applications', 'open_mobile_hash')) {
            DB::statement("alter table `origination_applications` add `open_mobile_hash` char(64) generated always as (case when `status` in ('draft', 'incomplete') then `mobile_hash` end) stored null after `mobile_hash`");
            DB::statement('alter table `origination_applications` add unique `origination_applications_open_mobile_hash_unique` (`open_mobile_hash`)');
        }

        $this->create('origination_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('origination_applications')->cascadeOnDelete();
            $table->string('type', 30);
            $table->foreignId('notice_id')->nullable()->constrained('origination_notices')->restrictOnDelete();
            $table->string('version', 20)->nullable();
            $table->char('text_hash', 64)->nullable();
            $table->boolean('accepted');
            $table->string('stage', 12);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('accepted_at');
            $table->timestamp('created_at')->nullable();
            $table->index(['application_id', 'type']);
        });

        $this->create('origination_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('origination_applications')->cascadeOnDelete();
            $table->string('public_id', 40)->unique();
            $table->string('type', 50);
            $table->enum('status', ['uploaded', 'pending_verification', 'verified', 'rejected', 'replacement_requested'])->default('uploaded');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'type']);
        });

        $this->create('origination_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('origination_documents')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('public_id', 40)->unique();
            $table->string('disk', 20);
            $table->string('path', 255);
            $table->string('file_name', 150);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->enum('scan_status', ['not_scanned', 'clean']);
            $table->string('scanner', 20)->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamp('uploaded_at');
            $table->unique(['document_id', 'version']);
            $table->unique(['document_id', 'sha256']);
        });

        if (Schema::hasTable('origination_documents') && ! $this->hasForeignKey('origination_documents', 'origination_documents_current_version_id_foreign')) {
            Schema::table('origination_documents', function (Blueprint $table): void {
                $table->foreign('current_version_id')->references('id')->on('origination_document_versions')->nullOnDelete();
            });
        }

        $this->create('origination_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('origination_applications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->nullable();
        });

        $this->create('origination_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('origination_applications')->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('actor_type', 12);
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['application_id', 'id']);
        });

        $this->seed();
    }

    public function down(): void
    {
        if (Schema::hasTable('origination_documents')) {
            Schema::table('origination_documents', function (Blueprint $table): void {
                $table->dropForeign(['current_version_id']);
            });
        }

        foreach ([
            'origination_status_history', 'origination_notes', 'origination_document_versions', 'origination_documents',
            'origination_consents', 'origination_applications', 'origination_link_products', 'origination_links',
            'origination_notices', 'origination_product_settings', 'origination_rule_revisions', 'origination_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function create(string $table, Closure $definition): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $definition);
        }
    }

    private function hasForeignKey(string $table, string $name): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(fn (array $key) => $key['name'] === $name);
    }

    /**
     * The settings row and rule revision 1, written straight to the tables so
     * a deploy writes no audit rows (seeders and migrations never do).
     */
    private function seed(): void
    {
        $now = now();

        if (! DB::table('origination_settings')->exists()) {
            DB::table('origination_settings')->insert([
                'online_applications_enabled' => false,
                'reference_prefix' => 'OA',
                'draft_expiration_days' => 30,
                'estimated_minutes' => 15,
                'accepted_file_types' => json_encode(['jpg', 'jpeg', 'png', 'pdf']),
                'max_upload_mb' => 10,
                'retention_days' => 365,
                'document_types' => json_encode([
                    ['type' => 'government_id', 'label' => 'Valid government ID', 'description' => 'A clear photo or scan of the front and back.', 'required' => true],
                    ['type' => 'proof_of_income', 'label' => 'Proof of income', 'description' => 'Your latest payslip, income tax return or business records.', 'required' => true],
                    ['type' => 'proof_of_address', 'label' => 'Proof of address', 'description' => 'A recent utility bill or barangay certificate.', 'required' => false],
                ]),
                'notifications' => json_encode([
                    'application_confirmation' => true,
                    'incomplete_reminder' => false,
                    'incomplete_reminder_after_days' => 3,
                    'document_request' => true,
                    'status_change' => true,
                    'decision' => true,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! DB::table('origination_rule_revisions')->exists()) {
            DB::table('origination_rule_revisions')->insert([
                'version' => 1,
                'rules' => json_encode([
                    'max_dti' => '40',
                    'min_coverage' => '1.25',
                    'min_remaining_income' => '0.00',
                    'income_recognition' => [
                        'employed' => '100', 'self_employed' => '100', 'business_owner' => '100',
                        'farmer' => '100', 'pensioner' => '100', 'other' => '100',
                    ],
                    'require_verified_income' => false,
                    'min_employment_years' => null,
                    'missing_information_rule' => 'provisional',
                    'product_overrides' => [],
                    'periods_per_year' => [
                        'daily' => 365, 'weekly' => 52, 'bi_weekly' => 26, 'semi_monthly' => 24,
                        'monthly' => 12, 'quarterly' => 4, 'semi_annual' => 2, 'annual' => 1,
                    ],
                ]),
                'created_at' => $now,
            ]);
        }
    }
};
