<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMLA customer due diligence and risk (Phase 6, AMLA-B4; AMLA design §4.8–10,
 * §4.14, §4.17):
 * - `amla_profiles`: one AMLA profile per existing member or walk-in, linked
 *   to it and never a second customer record. It holds only the KYC answers
 *   the member record doesn't (nationality, occupation, source of funds,
 *   purpose, expected activity, PEP status, adverse information), plus the
 *   review dates and when enhanced due diligence became required. PEP and
 *   adverse-information details are encrypted.
 * - `amla_profile_changes`: every change to a profile, old and new values,
 *   encrypted, kept forever (the KYC audit history).
 * - `amla_id_verifications`: a person's record of checking an ID, kept as
 *   written. A member's points at one of their own `valid_id` documents.
 * - `amla_risk_rule_versions`: immutable scoring rules, version 1 the
 *   documented starting points, "to verify".
 * - `amla_risk_assessments`: every assessment, computed or overridden with a
 *   reason, kept as written.
 * - `amla_reviews`: periodic, enhanced due diligence and change-of-circumstance
 *   reviews, kept as written.
 *
 * Members, walk-ins and users referenced here can't be deleted (restrict), so a
 * member with AMLA records is never purged. No money table changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amla_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 10);
            $table->foreignId('borrower_id')->nullable()->unique()->constrained('borrowers')->restrictOnDelete();
            $table->foreignId('gcash_non_member_id')->nullable()->unique()->constrained('gcash_non_members')->restrictOnDelete();
            $table->char('nationality', 2)->nullable();
            $table->string('occupation', 150)->nullable();
            $table->string('source_of_funds', 20)->nullable();
            $table->string('source_of_funds_other', 150)->nullable();
            $table->string('purpose', 255)->nullable();
            $table->unsignedBigInteger('expected_monthly_centavos')->nullable();
            $table->unsignedInteger('expected_monthly_count')->nullable();
            $table->string('pep_status', 20)->nullable();
            $table->text('pep_details')->nullable();
            $table->boolean('adverse_info')->nullable();
            $table->text('adverse_info_note')->nullable();
            $table->date('last_review_on')->nullable();
            $table->date('next_review_on')->nullable();
            $table->timestamp('edd_required_since')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('next_review_on');
        });

        Schema::create('amla_profile_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('amla_profiles')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('changes');
            $table->timestamp('created_at')->nullable();

            $table->index(['profile_id', 'id']);
        });

        Schema::create('amla_id_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('amla_profiles')->restrictOnDelete();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->string('id_type', 100);
            $table->string('id_number_last4', 4)->nullable();
            $table->string('method', 20);
            $table->string('result', 10);
            $table->date('expires_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['profile_id', 'id']);
        });

        Schema::create('amla_risk_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            // Text, not JSON: MySQL's JSON type reorders keys, and the rules read in their written order.
            $table->text('definition');
            $table->boolean('to_verify')->default(true);
            $table->timestamp('effective_at');
            $table->foreignId('request_id')->nullable()->constrained('amla_change_requests')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('amla_risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('amla_profiles')->restrictOnDelete();
            $table->foreignId('rule_version_id')->constrained('amla_risk_rule_versions')->restrictOnDelete();
            $table->unsignedInteger('score');
            $table->string('computed_level', 10);
            $table->string('level', 10);
            $table->boolean('overridden')->default(false);
            $table->boolean('override_carried')->default(false);
            $table->text('override_reason')->nullable();
            $table->json('factors');
            $table->string('trigger', 20);
            $table->foreignId('assessed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['profile_id', 'id']);
        });

        Schema::create('amla_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('amla_profiles')->restrictOnDelete();
            $table->string('kind', 20);
            $table->string('outcome', 20);
            $table->text('notes')->nullable();
            $table->date('reviewed_on');
            $table->date('next_review_on');
            $table->string('level_at_review', 10)->nullable();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['profile_id', 'id']);
        });

        /*
         * Version 1: the documented starting points (AMLA design §4.9), written
         * out here so a later code change never rewrites what a deployment
         * started from. "Starting point, not a legal threshold": to verify.
         */
        $now = now();

        DB::table('amla_risk_rule_versions')->insert([
            'version' => 1,
            'definition' => json_encode([
                'factors' => [
                    'customer_type' => ['member' => 0, 'walk_in' => 10],
                    'id_verification' => ['verified' => 0, 'not_verified' => 15, 'expired' => 15, 'failed' => 25],
                    'occupation' => ['provided' => 0, 'missing' => 10],
                    'source_of_funds' => ['salary' => 0, 'pension' => 0, 'savings' => 0, 'business' => 5, 'remittance' => 5, 'other' => 5, 'missing' => 10],
                    'expected_activity' => ['provided' => 0, 'missing' => 5],
                    'geography' => ['domestic' => 0, 'foreign' => 5, 'high_risk_country' => 25, 'missing' => 5],
                    'channel' => ['no_gcash' => 0, 'gcash' => 5],
                    'pep' => ['not_pep' => 0, 'pep' => 30, 'pep_family' => 25, 'pep_associate' => 20, 'missing' => 5],
                    'adverse_info' => ['none' => 0, 'flagged' => 20],
                ],
                'thresholds' => ['medium' => 20, 'high' => 40],
                'high_risk_countries' => ['KP', 'IR', 'MM'],
            ]),
            'to_verify' => true,
            'effective_at' => $now,
            'created_at' => $now,
        ]);
    }

    /**
     * AMLA records are kept forever: once a profile exists, this refuses rather
     * than drop it.
     */
    public function down(): void
    {
        if (Schema::hasTable('amla_profiles') && DB::table('amla_profiles')->exists()) {
            throw new RuntimeException('AMLA profiles are kept forever, so the AMLA CDD tables are not dropped.');
        }

        Schema::dropIfExists('amla_reviews');
        Schema::dropIfExists('amla_risk_assessments');
        Schema::dropIfExists('amla_risk_rule_versions');
        Schema::dropIfExists('amla_id_verifications');
        Schema::dropIfExists('amla_profile_changes');
        Schema::dropIfExists('amla_profiles');
    }
};
