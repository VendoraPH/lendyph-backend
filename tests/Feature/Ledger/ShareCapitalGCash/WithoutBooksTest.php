<?php

namespace Tests\Feature\Ledger\ShareCapitalGCash;

use App\Models\AccountingAccount;
use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
use App\Models\ShareCapitalLedger;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * An organisation that keeps no books (no chart of accounts): every share
 * capital and GCash action works as before, stores its method and
 * reference, and posts nothing.
 */
class WithoutBooksTest extends TestCase
{
    use SetupLendyPH;

    public function test_every_share_capital_and_gcash_action_works_and_nothing_posts(): void
    {
        $this->seedAndLogin();
        $this->assertSame(0, AccountingAccount::count());
        GCashTier::create(['min_amount' => 1, 'max_amount' => 5000, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);

        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $other = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $member->shareCapitalPledge->update(['amount' => 500, 'auto_credit' => true]);

        $this->postJson("/api/pledges/{$member->shareCapitalPledge->id}/entries", [
            'amount' => 100, 'type' => 'credit', 'date' => '2026-11-04', 'method' => 'gcash', 'reference_number' => 'GC-1',
        ])->assertCreated()->assertJsonPath('data.method', 'gcash');

        $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $other->id, 'date' => '2026-11-04', 'description' => 'Contribution', 'type' => 'credit', 'amount' => 200,
        ])->assertCreated()->assertJsonPath('data.method', 'cash');

        $this->postJson('/api/pledges/bulk-entries', [
            'method' => 'bank_transfer',
            'reference_number' => 'BDO-1',
            'entries' => [
                ['pledge_id' => $member->shareCapitalPledge->id, 'amount' => 50, 'type' => 'debit', 'date' => '2026-11-04'],
                ['pledge_id' => $other->shareCapitalPledge->id, 'amount' => 60, 'type' => 'credit', 'date' => '2026-11-04'],
            ],
        ])->assertCreated()->assertJsonPath('count', 2);

        $this->postJson('/api/auto-credit/process')->assertCreated()->assertJsonPath('data.member_count', 1);

        $pending = $this->postJson('/api/gcash/transactions', ['borrower_id' => $member->id, 'type' => 'cash_in', 'amount' => 1000, 'is_pending' => true])
            ->assertCreated()->json('data.id');
        $this->patchJson("/api/gcash/transactions/{$pending}/paid")->assertOk();
        $this->postJson('/api/gcash/transactions', ['borrower_id' => $member->id, 'type' => 'cash_in', 'amount' => 1200])->assertCreated();
        $this->postJson('/api/gcash/transactions', ['borrower_id' => $member->id, 'type' => 'cash_out', 'amount' => 1000])->assertCreated();

        $this->assertSame(5, ShareCapitalLedger::count());
        $this->assertSame(['bank_transfer', 'cash', 'gcash'], ShareCapitalLedger::distinct()->orderBy('method')->pluck('method')->all());
        $this->assertSame(3, GCashTransaction::count());
        $this->assertSame(0, AccountingJournal::count());
    }
}
