<?php

namespace Tests\Feature;

use App\Models\Borrower;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class ShareCapitalTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    // ── Ledger ────────────────────────────────────────────────────────────────

    public function test_list_ledger_entries(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $borrower->id,
            'date' => now()->toDateString(),
            'description' => 'Initial contribution',
            'type' => 'credit',
            'amount' => 2000,
        ])->assertCreated();

        $this->getJson('/api/share-capital/ledger')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_create_ledger_entry(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $borrower->id,
            'date' => now()->toDateString(),
            'description' => 'Initial contribution',
            'type' => 'credit',
            'amount' => 2000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.credit', 2000)
            ->assertJsonPath('data.debit', 0);

        $this->assertDatabaseHas('share_capital_ledger', [
            'borrower_id' => $borrower->id,
            'credit' => 2000,
            'debit' => 0,
        ]);
    }

    public function test_ledger_reference_is_auto_generated(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $borrower->id,
            'date' => now()->toDateString(),
            'description' => 'Test',
            'type' => 'debit',
            'amount' => 100,
        ]);

        $response->assertCreated();
        $this->assertStringStartsWith('SC-', $response->json('data.reference'));
    }

    public function test_ledger_filter_by_borrower(): void
    {
        $b1 = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $b2 = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->postJson('/api/share-capital/ledger', ['borrower_id' => $b1->id, 'date' => now()->toDateString(), 'description' => 'T', 'type' => 'credit', 'amount' => 500]);
        $this->postJson('/api/share-capital/ledger', ['borrower_id' => $b2->id, 'date' => now()->toDateString(), 'description' => 'T', 'type' => 'credit', 'amount' => 500]);

        $this->getJson("/api/share-capital/ledger?borrower_id={$b1->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ── Pledges ───────────────────────────────────────────────────────────────

    public function test_list_pledges(): void
    {
        Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $this->getJson('/api/pledges')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_update_pledge_amount_and_schedule(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $pledge = $borrower->shareCapitalPledge;

        $this->putJson("/api/pledges/{$pledge->id}", ['amount' => 1000, 'schedule' => '30'])
            ->assertOk()
            ->assertJsonPath('data.amount', 1000)
            ->assertJsonPath('data.schedule', '30');
    }

    public function test_toggle_auto_credit(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $pledge = $borrower->shareCapitalPledge;

        $this->patchJson("/api/pledges/{$pledge->id}/auto-credit")
            ->assertOk()
            ->assertJsonPath('auto_credit', true);

        $this->assertDatabaseHas('share_capital_pledges', ['id' => $pledge->id, 'auto_credit' => true]);
    }

    public function test_manual_entry_for_pledge(): void
    {
        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $pledge = $borrower->shareCapitalPledge;

        $response = $this->postJson("/api/pledges/{$pledge->id}/entries", [
            'amount' => 500,
            'type' => 'credit',
            'date' => now()->toDateString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.credit', 500);
    }

    public function test_bulk_entry(): void
    {
        $b1 = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $b2 = Borrower::factory()->create(['branch_id' => $this->branch->id]);

        $response = $this->postJson('/api/pledges/bulk-entries', [
            'entries' => [
                ['pledge_id' => $b1->shareCapitalPledge->id, 'amount' => 500, 'type' => 'credit', 'date' => now()->toDateString()],
                ['pledge_id' => $b2->shareCapitalPledge->id, 'amount' => 1000, 'type' => 'credit', 'date' => now()->toDateString()],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('count', 2);
    }
}
