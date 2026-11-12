<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Savings account notes (gap round S4): a timeline staff add to an account,
 * up to 1,000 characters each. Notes are never edited or deleted, so the
 * table has no `updated_at` and no soft delete.
 *
 * Created only when missing, so running it again changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('savings_account_notes')) {
            return;
        }

        Schema::create('savings_account_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained('savings_accounts')->restrictOnDelete();
            $table->string('note', 1000);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['account_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_account_notes');
    }
};
