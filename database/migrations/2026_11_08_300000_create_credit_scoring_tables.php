<?php

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
 * row. Revision 1.0 is a literal copied into this file, so a later change to
 * App\Services\CreditScoring\ScorecardV1 can never change what this
 * migration seeds; a test checks the two still agree. New tables only, so
 * nothing existing is changed and no audit row is due. Every step is
 * guarded, so the migration runs safely twice.
 *
 * `credit_decisions.borrower_id` restricts deleting a member who has a
 * decision, as `loans` does: the delete fails before anything cascades and
 * the purge's transaction rolls back whole. A member with scores but no
 * decision is deleted with them.
 */
return new class extends Migration
{
    public const REVISION_1_0 = <<<'JSON'
{
    "version": "1.0",
    "engine_version": "1.0.0",
    "notes": "Starting weights from the Credit Scoring task. Unvalidated: not yet tested against Lendy's own loan outcomes.",
    "categories": [
        {
            "key": "repayment",
            "label": "Repayment Behaviour",
            "weight_percent": 35,
            "description": "How the member has paid Lendy loans: on-time rate, lateness, current arrears, defaults and restructures."
        },
        {
            "key": "capacity",
            "label": "Payment Capacity",
            "weight_percent": 25,
            "description": "Whether the member can afford the payments: debt service ratio and disposable income cover."
        },
        {
            "key": "exposure",
            "label": "Credit Exposure",
            "weight_percent": 15,
            "description": "Existing obligations: past due amount, active loans and debt against annual income."
        },
        {
            "key": "stability",
            "label": "Stability",
            "weight_percent": 10,
            "description": "Job tenure or business age, and years at the current residence."
        },
        {
            "key": "relationship",
            "label": "Lendy Relationship",
            "weight_percent": 10,
            "description": "Membership tenure and loans completed with Lendy."
        },
        {
            "key": "application",
            "label": "Application Quality",
            "weight_percent": 5,
            "description": "Whether the lending information on file is complete and verified."
        }
    ],
    "factors": [
        {
            "id": 1,
            "key": "on_time_rate",
            "category_key": "repayment",
            "label": "On-time payment rate",
            "points": 15,
            "rule": "at_least",
            "bands": [
                {
                    "threshold": 95,
                    "points": 15
                },
                {
                    "threshold": 90,
                    "points": 12
                },
                {
                    "threshold": 80,
                    "points": 8
                },
                {
                    "threshold": 70,
                    "points": 4
                }
            ],
            "otherwise": 0,
            "is_active": true
        },
        {
            "id": 2,
            "key": "worst_days_late",
            "category_key": "repayment",
            "label": "Worst days late in the last 24 months",
            "points": 8,
            "rule": "at_most",
            "bands": [
                {
                    "threshold": 0,
                    "points": 8
                },
                {
                    "threshold": 7,
                    "points": 6
                },
                {
                    "threshold": 30,
                    "points": 3
                },
                {
                    "threshold": 60,
                    "points": 1
                }
            ],
            "otherwise": 0,
            "is_active": true
        },
        {
            "id": 3,
            "key": "days_past_due",
            "category_key": "repayment",
            "label": "Days past due now",
            "points": 7,
            "rule": "at_most",
            "bands": [
                {
                    "threshold": 0,
                    "points": 7
                },
                {
                    "threshold": 7,
                    "points": 4
                },
                {
                    "threshold": 30,
                    "points": 2
                }
            ],
            "otherwise": 0,
            "is_active": true
        },
        {
            "id": 4,
            "key": "defaults_restructures",
            "category_key": "repayment",
            "label": "Defaults and restructures",
            "points": 5,
            "rule": "at_most",
            "bands": [
                {
                    "threshold": 0,
                    "points": 5
                },
                {
                    "threshold": 1,
                    "points": 3
                }
            ],
            "otherwise": 1,
            "is_active": true,
            "default_points": 0
        },
        {
            "id": 5,
            "key": "debt_service_ratio",
            "category_key": "capacity",
            "label": "Debt service ratio",
            "points": 15,
            "rule": "at_most",
            "bands": [
                {
                    "threshold": 20,
                    "points": 15
                },
                {
                    "threshold": 30,
                    "points": 12
                },
                {
                    "threshold": 40,
                    "points": 8
                },
                {
                    "threshold": 50,
                    "points": 4
                }
            ],
            "otherwise": 0,
            "is_active": true
        },
        {
            "id": 6,
            "key": "payment_cover",
            "category_key": "capacity",
            "label": "Payment cover",
            "points": 10,
            "rule": "at_least",
            "bands": [
                {
                    "threshold": 3,
                    "points": 10
                },
                {
                    "threshold": 2,
                    "points": 7
                },
                {
                    "threshold": 1.5,
                    "points": 5
                },
                {
                    "threshold": 1,
                    "points": 2
                }
            ],
            "otherwise": 0,
            "is_active": true
        },
        {
            "id": 7,
            "key": "past_due_amount",
            "category_key": "exposure",
            "label": "Past due amount",
            "points": 6,
            "rule": "at_most",
            "bands": [
                {
                    "threshold": 0,
                    "points": 6
                },
                {
                    "threshold": 10,
                    "points": 3
                }
            ],
            "otherwise": 0,
            "is_active": true
        },
        {
            "id": 8,
            "key": "active_loans",
            "category_key": "exposure",
            "label": "Active loans",
            "points": 4,
            "rule": "at_most",
            "bands": [
                {
                    "threshold": 1,
                    "points": 4
                },
                {
                    "threshold": 2,
                    "points": 2
                }
            ],
            "otherwise": 0,
            "is_active": true
        },
        {
            "id": 9,
            "key": "debt_to_income",
            "category_key": "exposure",
            "label": "Debt against annual income",
            "points": 5,
            "rule": "at_most",
            "bands": [
                {
                    "threshold": 25,
                    "points": 5
                },
                {
                    "threshold": 50,
                    "points": 3
                },
                {
                    "threshold": 100,
                    "points": 1
                }
            ],
            "otherwise": 0,
            "is_active": true
        },
        {
            "id": 10,
            "key": "tenure",
            "category_key": "stability",
            "label": "Job tenure or business age",
            "points": 6,
            "rule": "at_least",
            "bands": [
                {
                    "threshold": 5,
                    "points": 6
                },
                {
                    "threshold": 2,
                    "points": 4
                },
                {
                    "threshold": 1,
                    "points": 2
                }
            ],
            "otherwise": 1,
            "is_active": true
        },
        {
            "id": 11,
            "key": "residence",
            "category_key": "stability",
            "label": "Years at residence",
            "points": 4,
            "rule": "at_least",
            "bands": [
                {
                    "threshold": 5,
                    "points": 4
                },
                {
                    "threshold": 2,
                    "points": 3
                },
                {
                    "threshold": 1,
                    "points": 1
                }
            ],
            "otherwise": 0,
            "is_active": true
        },
        {
            "id": 12,
            "key": "membership_tenure",
            "category_key": "relationship",
            "label": "Membership tenure",
            "points": 4,
            "rule": "at_least",
            "bands": [
                {
                    "threshold": 3,
                    "points": 4
                },
                {
                    "threshold": 1,
                    "points": 3
                }
            ],
            "otherwise": 1,
            "is_active": true
        },
        {
            "id": 13,
            "key": "completed_loans",
            "category_key": "relationship",
            "label": "Completed loans",
            "points": 4,
            "rule": "at_least",
            "bands": [
                {
                    "threshold": 3,
                    "points": 4
                },
                {
                    "threshold": 2,
                    "points": 3
                },
                {
                    "threshold": 1,
                    "points": 2
                }
            ],
            "otherwise": 0,
            "is_active": true
        },
        {
            "id": 14,
            "key": "clean_completed_loans",
            "category_key": "relationship",
            "label": "Completed loans never more than 7 days late",
            "points": 2,
            "rule": "at_least",
            "bands": [
                {
                    "threshold": 2,
                    "points": 2
                },
                {
                    "threshold": 1,
                    "points": 1
                }
            ],
            "otherwise": 0,
            "is_active": true
        },
        {
            "id": 15,
            "key": "valid_id",
            "category_key": "application",
            "label": "Valid ID on file",
            "points": 2,
            "rule": "at_least",
            "bands": [
                {
                    "threshold": 1,
                    "points": 2
                }
            ],
            "otherwise": 0,
            "is_active": true
        },
        {
            "id": 16,
            "key": "valid_mobile",
            "category_key": "application",
            "label": "Valid mobile number",
            "points": 1,
            "rule": "at_least",
            "bands": [
                {
                    "threshold": 1,
                    "points": 1
                }
            ],
            "otherwise": 0,
            "is_active": true
        },
        {
            "id": 17,
            "key": "income_verified",
            "category_key": "application",
            "label": "Income verified",
            "points": 1,
            "rule": "at_least",
            "bands": [
                {
                    "threshold": 1,
                    "points": 1
                }
            ],
            "otherwise": 0,
            "is_active": true
        },
        {
            "id": 18,
            "key": "verified_references",
            "category_key": "application",
            "label": "Two or more verified references",
            "points": 1,
            "rule": "at_least",
            "bands": [
                {
                    "threshold": 2,
                    "points": 1
                }
            ],
            "otherwise": 0,
            "is_active": true
        }
    ],
    "risk_bands": [
        {
            "key": "very_low",
            "label": "Very Low Risk",
            "min_score": 90,
            "recommendation": "Suitable for standard loan assessment."
        },
        {
            "key": "low",
            "label": "Low Risk",
            "min_score": 80,
            "recommendation": "Suitable for standard loan assessment."
        },
        {
            "key": "moderate",
            "label": "Moderate Risk",
            "min_score": 70,
            "recommendation": "Review the member's income and existing obligations before approval."
        },
        {
            "key": "elevated",
            "label": "Elevated Risk",
            "min_score": 60,
            "recommendation": "Additional assessment needed. Manual review recommended."
        },
        {
            "key": "high",
            "label": "High Risk",
            "min_score": 40,
            "recommendation": "High repayment risk. Review carefully before approving additional credit."
        },
        {
            "key": "very_high",
            "label": "Very High Risk",
            "min_score": 0,
            "recommendation": "Serious repayment risk. Review carefully before approving additional credit."
        }
    ],
    "policy_rules": [
        {
            "id": 1,
            "key": "past_due_over_limit",
            "type": "hard_flag",
            "label": "Loan more than 30 days past due",
            "description": "The member has a loan more than 30 days past due. Manual review required.",
            "is_active": true
        },
        {
            "id": 2,
            "key": "existing_default",
            "type": "hard_flag",
            "label": "Defaulted loan",
            "description": "The member has a defaulted loan.",
            "is_active": true
        },
        {
            "id": 3,
            "key": "previous_write_off",
            "type": "hard_flag",
            "label": "Previous write-off",
            "description": "Part of a previous loan of the member was written off.",
            "is_active": true
        },
        {
            "id": 4,
            "key": "income_verification_failed",
            "type": "hard_flag",
            "label": "Income verification failed",
            "description": "The member's declared income could not be verified.",
            "is_active": true
        },
        {
            "id": 5,
            "key": "past_due",
            "type": "soft_flag",
            "label": "Loan past due",
            "description": "The member has a loan 1 to 30 days past due.",
            "is_active": true
        },
        {
            "id": 6,
            "key": "too_many_active_loans",
            "type": "soft_flag",
            "label": "Active loans above policy limit",
            "description": "The member's active loans, outside lenders included, exceed the policy limit.",
            "is_active": true
        },
        {
            "id": 7,
            "key": "possible_duplicate",
            "type": "soft_flag",
            "label": "Possible duplicate member record",
            "description": "Another member record has the same name.",
            "is_active": true
        },
        {
            "id": 8,
            "key": "debt_service_above_caution",
            "type": "soft_flag",
            "label": "Debt service ratio above caution",
            "description": "Debt payments take more of the member's income than the caution level.",
            "is_active": true
        },
        {
            "id": 9,
            "key": "notice_not_recorded",
            "type": "soft_flag",
            "label": "Credit assessment notice not recorded",
            "description": "The member's acknowledgement of the credit assessment notice is not recorded for this loan.",
            "is_active": true
        }
    ]
}
JSON;

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
                $table->unsignedInteger('attempts')->default(0);
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
                $table->foreignId('borrower_id')->constrained('borrowers')->restrictOnDelete();
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

        $revision = json_decode(self::REVISION_1_0, true, flags: JSON_THROW_ON_ERROR);

        if (! DB::table('credit_scorecard_revisions')->where('version', $revision['version'])->exists()) {
            DB::table('credit_scorecard_revisions')->insert([
                'version' => $revision['version'],
                'engine_version' => $revision['engine_version'],
                'categories' => json_encode($revision['categories']),
                'factors' => json_encode($revision['factors']),
                'risk_bands' => json_encode($revision['risk_bands']),
                'policy_rules' => json_encode($revision['policy_rules']),
                'validated' => false,
                'notes' => $revision['notes'],
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! DB::table('credit_scoring_settings')->exists()) {
            DB::table('credit_scoring_settings')->insert([
                'current_revision_id' => DB::table('credit_scorecard_revisions')->where('version', $revision['version'])->value('id'),
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
