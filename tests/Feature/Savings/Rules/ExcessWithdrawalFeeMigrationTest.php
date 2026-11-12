<?php

namespace Tests\Feature\Savings\Rules;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The excess-withdrawal fee migration guards each column on its own (gap
 * round S1): a database that ran an earlier version, holding only some of
 * the columns, gets the missing ones, and running it again changes nothing.
 */
class ExcessWithdrawalFeeMigrationTest extends TestCase
{
    /** It runs DDL, which MySQL commits at once: this needs a real migrate:fresh before it. */
    protected bool $wrapsEachTestInTransaction = false;

    private const COLUMNS = [
        'savings_approvals' => ['fee_amount', 'counted_on', 'requested_at', 'rule_free_withdrawals', 'rule_excess_fee'],
        'portal_requests' => ['fee_amount', 'counted_on', 'requested_at', 'rule_free_withdrawals', 'rule_excess_fee'],
        'savings_transactions' => ['counted_on', 'requested_at', 'fee_for_transaction_id'],
    ];

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_13_120200_add_excess_withdrawal_fee_columns.php');
    }

    public function test_a_partly_migrated_database_gets_each_missing_column_and_a_rerun_changes_nothing(): void
    {
        Schema::table('savings_approvals', fn (Blueprint $table) => $table->dropColumn(['counted_on', 'requested_at', 'rule_excess_fee']));
        Schema::table('portal_requests', fn (Blueprint $table) => $table->dropColumn(['rule_free_withdrawals', 'requested_at']));
        Schema::table('savings_transactions', fn (Blueprint $table) => $table->dropColumn(['counted_on', 'requested_at']));

        $this->migration()->up();
        $this->migration()->up();

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "{$table}.{$column} is missing.");
            }
        }
    }

    public function test_down_then_up_round_trips(): void
    {
        $this->migration()->down();

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertFalse(Schema::hasColumn($table, $column), "{$table}.{$column} is still there.");
            }
        }

        $this->migration()->up();
        $this->assertTrue(Schema::hasColumn('savings_transactions', 'fee_for_transaction_id'));
    }
}
