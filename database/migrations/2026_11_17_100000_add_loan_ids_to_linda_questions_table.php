<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linda round 2: the loans an answer named (a lookup's loan, a member's open
 * loans, the loans of the payments listed), beside `member_ids`. Ids only,
 * never text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('linda_questions', function (Blueprint $table): void {
            $table->json('loan_ids')->nullable()->after('member_ids');
        });
    }

    public function down(): void
    {
        Schema::table('linda_questions', function (Blueprint $table): void {
            $table->dropColumn('loan_ids');
        });
    }
};
