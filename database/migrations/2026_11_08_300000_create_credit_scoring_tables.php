<?php

use App\Services\CreditScoring\ScorecardV1;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credit Scoring's tables (Phase 2, decided 2026-11-08). The score is advisory
 * only: nothing here references a loan in a way that could change it.
 *
 * - `credit_scorecard_revisions` and `credit_scores` are never changed once
 *   written (their models throw on update and delete); `credit_decisions` is
 *   append-only. Every score names the revision that produced it.
 * - `credit_score_members` holds each member's current score, so lists read
 *   one row per member; `credit_scores` is the history, written only when a
 *   score, band, confidence or revision changes.
 * - `credit_score_dirty_members` and `credit_scoring_cursors` drive the
 *   minute job: a watermark per source table, and the members whose data
 *   changed since.
 *
 * Seeds revision 1.0 (the task's weights, unvalidated) and the one settings
 * row. New tables only, so nothing existing is changed and no audit row is
 * due. Every step is guarded, so the migration runs safely twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('credit_scorecard_revisions')) {
            Schema::create('credit_scorecard_revisions', function (Blueprint $table) {
                $table->id();
                $table->string('version', 20)->unique();
                $table->string('engine_version', 20);
                $table->json('categories');
                $table->json('factors');
                $table->json('risk_bands');
                $table->json('policy_rules');
                $table->boolean('validated')->default(false);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('credit_scoring_settings')) {
            Schema::create('credit_scoring_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('current_revision_id')->constrained('credit_scorecard_revisions')->restrictOnDelete();
                $table->decimal('dsr_manageable_max', 7, 4)->default(30);
                $table->decimal('dsr_caution_max', 7, 4)->default(40);
                $table->decimal('capacity_share', 7, 4)->default(40);
                $table->unsignedSmallInteger('max_active_loans')->default(2);
                $table->unsignedSmallInteger('hard_past_due_days')->default(30);
                $table->unsignedSmallInteger('decline_alert_points')->default(10);
                $table->string('notice_version', 20)->default('1.0');
                $table->text('privacy_notice');
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('credit_application_profiles')) {
            Schema::create('credit_application_profiles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('loan_id')->unique()->constrained('loans')->cascadeOnDelete();
                $table->decimal('other_income', 14, 2)->nullable();
                $table->decimal('business_income', 14, 2)->nullable();
                $table->decimal('household_expenses', 14, 2)->nullable();
                $table->decimal('outside_debt_payments', 14, 2)->nullable();
                $table->unsignedSmallInteger('outside_lenders_count')->nullable();
                $table->string('employment_status', 30)->nullable();
                $table->date('business_start_date')->nullable();
                $table->decimal('years_at_residence', 5, 2)->nullable();
                $table->unsignedSmallInteger('verified_references_count')->nullable();
                $table->enum('income_verification', ['verified', 'failed'])->nullable();
                $table->string('notice_version', 20)->nullable();
                $table->dateTime('notice_acknowledged_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('credit_scores')) {
            Schema::create('credit_scores', function (Blueprint $table) {
                $table->id();
                $table->foreignId('borrower_id')->constrained('borrowers')->cascadeOnDelete();
                $table->foreignId('loan_id')->nullable()->constrained('loans')->nullOnDelete();
                $table->foreignId('revision_id')->constrained('credit_scorecard_revisions')->restrictOnDelete();
                $table->string('revision_version', 20);
                $table->enum('context', ['nightly', 'assessment']);
                $table->enum('score_type', ['application', 'behavioral']);
                $table->enum('status', ['scored', 'insufficient_data']);
                $table->unsignedTinyInteger('score')->nullable();
                $table->string('risk_level', 20)->nullable();
                $table->enum('confidence', ['high', 'medium', 'low']);
                $table->string('confidence_reason', 500);
                $table->boolean('limited_history')->default(false);
                $table->json('category_scores');
                $table->json('capacity')->nullable();
                $table->json('data_used');
                $table->string('recommendation', 500);
                $table->string('recalculation_reason', 255);
                $table->unsignedTinyInteger('previous_score')->nullable();
                $table->dateTime('calculated_at');
                $table->timestamp('created_at')->nullable();

                $table->index(['borrower_id', 'context', 'calculated_at']);
                $table->index(['loan_id', 'calculated_at']);
                $table->index('calculated_at');
            });
        }

        if (! Schema::hasTable('credit_score_factors')) {
            Schema::create('credit_score_factors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('credit_score_id')->constrained('credit_scores')->cascadeOnDelete();
                $table->string('factor_key', 50);
                $table->string('category_key', 30);
                $table->string('label', 120);
                $table->boolean('has_data');
                $table->json('raw_value')->nullable();
                $table->unsignedTinyInteger('points_earned')->default(0);
                $table->unsignedTinyInteger('points_possible');
                $table->enum('impact', ['positive', 'risk', 'neutral']);
                $table->string('detail', 255);
                $table->timestamp('created_at')->nullable();
            });
        }

        if (! Schema::hasTable('credit_score_members')) {
            Schema::create('credit_score_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('borrower_id')->unique()->constrained('borrowers')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('credit_score_id')->constrained('credit_scores')->cascadeOnDelete();
                $table->foreignId('previous_credit_score_id')->nullable()->constrained('credit_scores')->nullOnDelete();
                $table->enum('status', ['scored', 'insufficient_data']);
                $table->enum('score_type', ['application', 'behavioral']);
                $table->unsignedTinyInteger('score')->nullable();
                $table->string('risk_level', 20)->nullable();
                $table->enum('confidence', ['high', 'medium', 'low']);
                $table->unsignedTinyInteger('previous_score')->nullable();
                $table->enum('trend', ['up', 'down', 'flat'])->default('flat');
                $table->boolean('has_active_loan')->default(false);
                $table->boolean('past_due')->default(false);
                $table->dateTime('calculated_at');
                $table->dateTime('checked_at');
                $table->timestamps();

                $table->index(['risk_level', 'score']);
                $table->index('branch_id');
                $table->index('calculated_at');
            });
        }

        if (! Schema::hasTable('credit_score_dirty_members')) {
            Schema::create('credit_score_dirty_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('borrower_id')->unique()->constrained('borrowers')->cascadeOnDelete();
                $table->string('reason', 100);
                $table->dateTime('flagged_at');
                $table->timestamps();

                $table->index('flagged_at');
            });
        }

        if (! Schema::hasTable('credit_scoring_cursors')) {
            Schema::create('credit_scoring_cursors', function (Blueprint $table) {
                $table->id();
                $table->string('source', 50)->unique();
                $table->dateTime('watermark')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('credit_policy_flags')) {
            Schema::create('credit_policy_flags', function (Blueprint $table) {
                $table->id();
                $table->foreignId('borrower_id')->constrained('borrowers')->cascadeOnDelete();
                $table->foreignId('loan_id')->nullable()->constrained('loans')->nullOnDelete();
                $table->string('rule_key', 50);
                $table->enum('type', ['hard_flag', 'soft_flag']);
                $table->string('label', 120);
                $table->string('detail', 255);
                $table->dateTime('first_triggered_at');
                $table->dateTime('last_seen_at');
                $table->dateTime('cleared_at')->nullable();
                $table->timestamps();

                $table->index(['borrower_id', 'cleared_at']);
                $table->index(['type', 'first_triggered_at']);
            });
        }

        if (! Schema::hasTable('credit_decisions')) {
            Schema::create('credit_decisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('borrower_id')->constrained('borrowers')->cascadeOnDelete();
                $table->foreignId('loan_id')->nullable()->constrained('loans')->nullOnDelete();
                $table->foreignId('credit_score_id')->constrained('credit_scores')->restrictOnDelete();
                $table->enum('decision', ['approve', 'decline', 'refer', 'hold']);
                $table->string('reason', 255);
                $table->text('remarks')->nullable();
                $table->json('score_snapshot');
                $table->string('recommendation_snapshot', 500);
                $table->string('revision_version', 20);
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('decided_at');
                $table->timestamp('created_at')->nullable();

                $table->index(['borrower_id', 'decided_at']);
                $table->index('loan_id');
            });
        }

        if (! Schema::hasTable('credit_risk_alerts')) {
            Schema::create('credit_risk_alerts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('borrower_id')->constrained('borrowers')->cascadeOnDelete();
                $table->foreignId('credit_score_id')->constrained('credit_scores')->cascadeOnDelete();
                $table->enum('kind', ['score_decline', 'high_risk']);
                $table->unsignedTinyInteger('previous_score')->nullable();
                $table->unsignedTinyInteger('current_score');
                $table->string('previous_risk_level', 20)->nullable();
                $table->string('current_risk_level', 20);
                $table->enum('severity', ['info', 'warning', 'critical']);
                $table->string('message', 255);
                $table->timestamps();

                $table->index('created_at');
                $table->index('borrower_id');
            });
        }

        $this->seedRevisionAndSettings();
    }

    public function down(): void
    {
        foreach ([
            'credit_risk_alerts',
            'credit_decisions',
            'credit_policy_flags',
            'credit_scoring_cursors',
            'credit_score_dirty_members',
            'credit_score_members',
            'credit_score_factors',
            'credit_scores',
            'credit_application_profiles',
            'credit_scoring_settings',
            'credit_scorecard_revisions',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function seedRevisionAndSettings(): void
    {
        $now = now();

        if (! DB::table('credit_scorecard_revisions')->where('version', ScorecardV1::VERSION)->exists()) {
            DB::table('credit_scorecard_revisions')->insert([
                'version' => ScorecardV1::VERSION,
                'engine_version' => ScorecardV1::ENGINE_VERSION,
                'categories' => json_encode(ScorecardV1::categories()),
                'factors' => json_encode(ScorecardV1::factors()),
                'risk_bands' => json_encode(ScorecardV1::riskBands()),
                'policy_rules' => json_encode(ScorecardV1::policyRules()),
                'validated' => false,
                'notes' => ScorecardV1::NOTES,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! DB::table('credit_scoring_settings')->exists()) {
            DB::table('credit_scoring_settings')->insert([
                'current_revision_id' => DB::table('credit_scorecard_revisions')->where('version', ScorecardV1::VERSION)->value('id'),
                'dsr_manageable_max' => 30,
                'dsr_caution_max' => 40,
                'capacity_share' => 40,
                'max_active_loans' => 2,
                'hard_past_due_days' => 30,
                'decline_alert_points' => 10,
                'notice_version' => '1.0',
                'privacy_notice' => 'Your information may be used to assess your creditworthiness and repayment capacity. '
                    .'The assessment may consider information such as your loan history, payment history, existing obligations, '
                    .'income and other information provided during your loan application. The score is advice for our staff; '
                    .'a person makes every loan decision.',
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
