<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chart of accounts — every other accounting table eventually points here.
 *
 * Deliberately NOT seeded by this migration. A chart is organisation-specific
 * (a cooperative's equity section is not a lending corporation's), so it is
 * created once, explicitly, through `POST /accounting/accounts/seed`, which
 * refuses to run over a chart that already exists. A migration that seeded it
 * would hand every deployment a corporate chart it never asked for and could
 * only then edit around.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_accounts', function (Blueprint $table) {
            $table->id();

            // "1010". Fixed-width numeric strings sort lexically into statement
            // order, which is why this is a string and not an integer: ordering
            // by it is the order every statement and the account tree present.
            $table->string('code', 16)->unique();
            $table->string('name', 160);
            $table->enum('type', ['asset', 'liability', 'equity', 'income', 'expense']);

            // Derived server-side from `type` + `is_contra` and never accepted
            // from a client — see App\Services\Accounting\AccountRules. Stored
            // rather than computed on read so the trial balance, the ledger and
            // the balance sheet cannot disagree about which way an account grows.
            $table->enum('normal_balance', ['debit', 'credit']);

            // Contra accounts carry the opposite balance to their type and
            // SUBTRACT from it: 1200 Allowance for Credit Losses is an asset
            // with a credit balance, and Net Loans Receivable is gross minus it.
            $table->boolean('is_contra')->default(false);

            // restrictOnDelete, not cascade: deleting a heading must never take
            // its children — and the ledger history hanging off them — with it.
            $table->foreignId('parent_id')->nullable()->constrained('accounting_accounts')->restrictOnDelete();

            // Headings. Their displayed balance is the sum of their subtree, so
            // posting to a group as well as its children double-counts.
            $table->boolean('is_group')->default(false);

            // Deactivated rather than deleted is the normal end of an account's
            // life: it stops accepting new history while keeping what it has.
            $table->boolean('is_active')->default(true);

            // Set on the money accounts that appear on the Cash & Bank screen.
            $table->enum('cash_kind', ['cash', 'bank', 'gcash', 'maya', 'wallet'])->nullable();

            // nullOnDelete: who added an account is useful, but losing the user
            // row must not take the account out of the chart with it.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Statement building walks one classification at a time, in code
            // order. `code` is already uniquely indexed for the plain ordering.
            $table->index(['type', 'code']);

            // The Cash & Bank screen selects on this alone.
            $table->index('cash_kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_accounts');
    }
};
