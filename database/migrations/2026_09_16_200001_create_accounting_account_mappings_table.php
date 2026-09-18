<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which account each posting role resolves to.
 *
 * The posting engine never names an account directly — it asks for a role
 * ("interest_income") and this table answers with an id. That indirection is
 * what lets an organisation re-point a role at its own account without anyone
 * editing a posting rule, and it is the reason a cooperative and a lending
 * corporation can share one engine.
 *
 * One row per role, enforced by the unique index: two rows for
 * `interest_income` would make "which account does interest post to?" a
 * question with two answers and no way to tell which one the engine used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_account_mappings', function (Blueprint $table) {
            $table->id();

            // The posting role: `cash`, `loans_receivable`, `interest_income`…
            // Kept as a string rather than an enum so a new role ships as a row
            // instead of an ALTER TABLE on every deployment.
            $table->string('role', 48)->unique();

            // restrictOnDelete is the point of this constraint. An account that
            // an automatic entry resolves through must not be deletable out from
            // under the engine — the next release or collection would fail at
            // the worst possible moment. Deactivate it and re-point the role.
            $table->foreignId('accounting_account_id')->constrained('accounting_accounts')->restrictOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_account_mappings');
    }
};
