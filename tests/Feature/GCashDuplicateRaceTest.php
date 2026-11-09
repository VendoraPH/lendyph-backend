<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The GCash duplicate check runs under the reference lock (Lead's call 5), on
 * two real connections: an identical create can't commit between this
 * create's duplicate check and its own insert, so two identical creates are
 * never both saved.
 *
 * Before, the check was a plain read taken before the reference lock: the
 * other create committed after the check had passed, the reference lock then
 * read past it, and both rows were saved.
 */
class GCashDuplicateRaceTest extends TestCase
{
    use SetupLendyPH;

    /**
     * Opted out of the per-test transaction: the other connection can only
     * see rows this one has committed.
     */
    protected bool $wrapsEachTestInTransaction = false;

    private const OTHER_CONNECTION = 'gcash_duplicate_race';

    private Borrower $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        GCashTier::create(['min_amount' => 1, 'max_amount' => 5000, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);

        config(['database.connections.'.self::OTHER_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
        DB::purge(self::OTHER_CONNECTION);
        DB::connection(self::OTHER_CONNECTION)->statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        DB::purge(self::OTHER_CONNECTION);

        parent::tearDown();
    }

    public function test_an_identical_create_cannot_commit_between_the_duplicate_check_and_the_insert(): void
    {
        $prefix = 'GC-'.now()->format('Ymd').'-';
        $last = GCashTransaction::where('reference_no', 'like', $prefix.'%')->max('reference_no');
        $otherReference = $prefix.str_pad((string) ($last === null ? 1 : (int) substr($last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);

        $checked = false;
        $otherSaved = null;

        DB::listen(function (QueryExecuted $query) use (&$checked, &$otherSaved, $otherReference): void {
            if ($checked || $query->connectionName === self::OTHER_CONNECTION
                || ! str_contains($query->sql, 'gcash_transactions') || ! str_contains($query->sql, 'transaction_date')) {
                return;
            }

            $checked = true;

            // The other create, for the same member, type and amount, commits now.
            $other = DB::connection(self::OTHER_CONNECTION);

            try {
                $other->transaction(fn () => $other->table('gcash_transactions')->insert([
                    'reference_no' => $otherReference,
                    'transaction_date' => now(),
                    'type' => 'cash_in',
                    'amount' => 1000,
                    'charge_amount' => 20,
                    'total_amount' => 1020,
                    'status' => 'paid',
                    'paid_at' => now(),
                    'borrower_id' => $this->member->id,
                    'transactor_user_id' => $this->admin->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
                $otherSaved = true;
            } catch (QueryException) {
                $otherSaved = false;
            }
        });

        $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->member->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ]);

        $this->assertTrue($checked, 'the duplicate check ran');
        $this->assertNotNull($otherSaved);
        $this->assertSame(1, GCashTransaction::query()
            ->where('borrower_id', $this->member->id)
            ->where('type', 'cash_in')
            ->where('amount', 1000)
            ->count(), 'two identical creates were both saved');
    }
}
