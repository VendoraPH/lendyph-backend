<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linda's question log (Linda v1 plan, "Privacy and the log"): one row per
 * question, recording who asked, when, how it was understood (the matcher,
 * the local guard, the model or neither), the intent, the answer function, the outcome, the
 * period, the branch scope (null means every branch), the members the answer
 * listed, the model, its tokens, the cost in pesos (6 decimals: a question
 * costs fractions of a centavo) and the time taken.
 *
 * There is deliberately no column for the question or the answer: no Linda
 * text is ever stored. Kept `linda.retention_days` (365) days by
 * `linda:prune`; a member purge removes the member's id from `member_ids`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('linda_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('asked_at');
            $table->string('source', 20);
            $table->string('intent', 50)->nullable();
            $table->string('answer_function', 100)->nullable();
            $table->string('outcome', 30)->nullable();
            $table->string('error_code', 30)->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->json('branch_scope')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->json('member_ids')->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->decimal('cost', 12, 6)->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();

            $table->index('asked_at');
            $table->index(['user_id', 'asked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('linda_questions');
    }
};
