<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A new GCash transaction's audit row reads in words. The Audit Trail prints a
 * row's description as stored, so it never carries the type or status keys
 * ("cash_out", "status=completed"). Rows already stored keep their text.
 */
class GCashAuditWordsTest extends TestCase
{
    use SetupLendyPH;

    private Borrower $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        GCashTier::create(['min_amount' => 1, 'max_amount' => 5000, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);
    }

    public function test_a_cash_out_reads_in_words(): void
    {
        $this->postJson('/api/gcash/transactions', ['borrower_id' => $this->member->id, 'type' => 'cash_out', 'amount' => 1000])->assertCreated();
        $tx = GCashTransaction::query()->latest('id')->firstOrFail();

        $this->assertSame("GCash Cash Out {$tx->reference_no} recorded (Completed)", $this->createdDescription($tx));
    }

    public function test_a_cash_in_reads_in_words(): void
    {
        $this->postJson('/api/gcash/transactions', ['borrower_id' => $this->member->id, 'type' => 'cash_in', 'amount' => 1000])->assertCreated();
        $tx = GCashTransaction::query()->latest('id')->firstOrFail();

        $this->assertSame("GCash Cash In {$tx->reference_no} recorded (".ucfirst($tx->status).')', $this->createdDescription($tx));
    }

    private function createdDescription(GCashTransaction $tx): string
    {
        return (string) AuditLog::query()
            ->where('auditable_type', GCashTransaction::class)
            ->where('auditable_id', $tx->id)
            ->where('action', 'created')
            ->value('description');
    }
}
