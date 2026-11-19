<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The share_capital_ledger.branch_id migration, both ways (Phase 6 MB-BE3).
 */
class ShareCapitalLedgerBranchMigrationTest extends TestCase
{
    /**
     * down() and up() run DDL, which MySQL commits at once, so this needs the
     * real `migrate:fresh` before each test that Tests\TestCase gives when
     * this is false.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_19_300000_add_branch_id_to_share_capital_ledger.php');
    }

    public function test_down_removes_the_column_and_up_adds_it_back(): void
    {
        $this->assertTrue(Schema::hasColumn('share_capital_ledger', 'branch_id'));

        $migration = $this->migration();
        $migration->down();
        $this->assertFalse(Schema::hasColumn('share_capital_ledger', 'branch_id'));
        $this->assertNotContains('share_capital_ledger_branch_date_index', Schema::getIndexListing('share_capital_ledger'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('share_capital_ledger', 'branch_id'));
        $this->assertContains('share_capital_ledger_branch_date_index', Schema::getIndexListing('share_capital_ledger'));
    }
}
