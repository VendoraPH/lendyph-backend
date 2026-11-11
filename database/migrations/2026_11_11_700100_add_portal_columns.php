<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member Portal v1 (design §2, column additions), on existing tables. Every
 * column is new and nullable or defaulted, so no existing row changes meaning:
 *
 * - `audit_logs.actor_type` and `portal_user_id`: the `member` actor. A null
 *   `actor_type` keeps today's meaning (staff, or System when `user_id` is
 *   null). Existing rows are untouched.
 * - `documents.portal_shared_at` and `portal_shared_by`: "Share with member".
 *   Null means not shared, which is every existing file.
 * - `savings_products.portal_account_requests` (off) and
 *   `portal_withdrawal_requests` (on): which requests a product takes.
 *
 * Each column is added only when missing, so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('audit_logs', 'actor_type')) {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->string('actor_type', 12)->nullable()->after('user_id');
                $table->foreignId('portal_user_id')->nullable()->after('actor_type')->constrained('portal_users')->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('documents', 'portal_shared_at')) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->timestamp('portal_shared_at')->nullable();
                $table->foreignId('portal_shared_by')->nullable()->constrained('users')->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('savings_products', 'portal_account_requests')) {
            Schema::table('savings_products', function (Blueprint $table): void {
                $table->boolean('portal_account_requests')->default(false);
                $table->boolean('portal_withdrawal_requests')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('savings_products', 'portal_account_requests')) {
            Schema::table('savings_products', function (Blueprint $table): void {
                $table->dropColumn(['portal_account_requests', 'portal_withdrawal_requests']);
            });
        }

        if (Schema::hasColumn('documents', 'portal_shared_at')) {
            Schema::table('documents', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('portal_shared_by');
                $table->dropColumn('portal_shared_at');
            });
        }

        if (Schema::hasColumn('audit_logs', 'actor_type')) {
            Schema::table('audit_logs', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('portal_user_id');
                $table->dropColumn('actor_type');
            });
        }
    }
};
