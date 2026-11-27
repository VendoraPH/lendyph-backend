<?php

namespace Tests\Feature\Accounting\OpeningBalances;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The lock order itself, read off the query log: the allowance account row
 * first (when a line is on it), then 3050, then the screen's draft, before any
 * journal lock. OpeningBalanceLockOrderTest proves the outcome with two
 * processes; this pins the mechanism, for the screen and the CLI alike.
 */
class OpeningBalanceLockSequenceTest extends TestCase
{
    use OpeningBalanceTestHelpers;

    public function test_the_allowance_row_is_the_first_lock_then_3050_then_the_draft(): void
    {
        $this->setUpOpeningBalances();
        $version = (int) $this->save([
            ['account_id' => $this->account('1110'), 'debit' => 1_000_000, 'credit' => 0],
            ['account_id' => $this->account('1200'), 'debit' => 0, 'credit' => 100_000],
            ['account_id' => $this->account('3050'), 'debit' => 0, 'credit' => 900_000],
        ])->assertOk()->json('data.edit_version');

        $locking = [];
        DB::listen(static function ($query) use (&$locking): void {
            if (str_contains(strtolower($query->sql), 'for update')) {
                $locking[] = [$query->sql, $query->bindings];
            }
        });

        $this->finalise($version)->assertOk();

        $order = array_map(fn (array $lock): string => $this->describe($lock[0], $lock[1]), $locking);
        $this->assertSame(
            ['account '.$this->account('1200'), 'account 3050', 'draft'],
            array_slice($order, 0, 3),
            "Locking reads, in order:\n".implode("\n", $order),
        );
        $this->assertNotContains('draft', array_slice($order, 3), 'The draft was locked twice, once after a journal lock.');
    }

    public function test_the_cli_takes_the_allowance_row_first_too(): void
    {
        $this->setUpOpeningBalances();
        $path = tempnam(sys_get_temp_dir(), 'openbal').'.csv';
        file_put_contents($path, "1110,10000.00,\n1200,,1000.00\n");

        $locking = [];
        DB::listen(static function ($query) use (&$locking): void {
            if (str_contains(strtolower($query->sql), 'for update')) {
                $locking[] = [$query->sql, $query->bindings];
            }
        });

        try {
            $this->artisan('accounting:opening-balances', ['--file' => $path, '--as-of' => self::AS_OF, '--force' => true])->assertSuccessful();
        } finally {
            unlink($path);
        }

        $order = array_map(fn (array $lock): string => $this->describe($lock[0], $lock[1]), $locking);
        $this->assertSame(['account '.$this->account('1200'), 'account 3050'], array_slice($order, 0, 2), implode("\n", $order));
    }

    /** @param  array<int, mixed>  $bindings */
    private function describe(string $sql, array $bindings): string
    {
        $sql = strtolower($sql);

        return match (true) {
            // The allowance by its id, 3050 by its code.
            str_contains($sql, 'from `accounting_accounts`') => 'account '.$bindings[0],
            str_contains($sql, 'from `accounting_opening_balances`') => 'draft',
            str_contains($sql, 'from `accounting_journals`') => 'journals',
            default => $sql,
        };
    }
}
