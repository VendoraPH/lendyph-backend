<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linda round 3, open questions: whether the model's written answer was
 * shown (`used`) or a fixed reply replaced it (`fell_back`). Null for every
 * other question. Never text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('linda_questions', function (Blueprint $table): void {
            $table->string('open_answer', 16)->nullable()->after('loan_ids');
        });
    }

    public function down(): void
    {
        Schema::table('linda_questions', function (Blueprint $table): void {
            $table->dropColumn('open_answer');
        });
    }
};
