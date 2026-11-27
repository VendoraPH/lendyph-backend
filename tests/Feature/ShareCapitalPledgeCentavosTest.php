<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\ShareCapitalPledge;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The Share Capital page saves pledges and ledger entries in exact centavos.
 *
 * The columns are decimal(12,2), and the models cast them `decimal:2`, which
 * rounds a third decimal away without a word: ₱1,500.555 was stored as
 * ₱1,500.56 and answered 200, so nobody could tell the figure saved was not
 * the one sent. The three requests the page saves through now accept up to two
 * decimals and refuse more with a 422 on the field, the same `decimal:0,2`
 * rule the release insurance fields use.
 */
class ShareCapitalPledgeCentavosTest extends TestCase
{
    use SetupLendyPH;

    private ShareCapitalPledge $pledge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $borrower = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $this->pledge = $borrower->shareCapitalPledge;
    }

    public function test_a_pledge_amount_with_centavos_is_saved_exactly(): void
    {
        $this->putJson("/api/pledges/{$this->pledge->id}", ['amount' => 1500.55])
            ->assertOk()
            ->assertJsonPath('data.amount', 1500.55);

        $this->assertSame('1500.55', $this->pledge->fresh()->getRawOriginal('amount'));
    }

    public function test_a_pledge_amount_with_more_than_two_decimals_is_refused_not_rounded(): void
    {
        $before = $this->pledge->fresh()->getRawOriginal('amount');

        $this->putJson("/api/pledges/{$this->pledge->id}", ['amount' => 1500.555])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->assertSame($before, $this->pledge->fresh()->getRawOriginal('amount'));
    }

    public function test_a_manual_entry_with_centavos_is_saved_exactly(): void
    {
        $this->postJson("/api/pledges/{$this->pledge->id}/entries", [
            'amount' => 1500.55,
            'type' => 'credit',
            'date' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.credit', 1500.55);

        $this->assertDatabaseHas('share_capital_ledger', [
            'borrower_id' => $this->pledge->borrower_id,
            'credit' => '1500.55',
        ]);
    }

    public function test_a_manual_entry_with_more_than_two_decimals_is_refused_not_rounded(): void
    {
        $this->postJson("/api/pledges/{$this->pledge->id}/entries", [
            'amount' => 1500.555,
            'type' => 'credit',
            'date' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->assertDatabaseMissing('share_capital_ledger', ['borrower_id' => $this->pledge->borrower_id]);
    }

    public function test_a_ledger_entry_with_more_than_two_decimals_is_refused_not_rounded(): void
    {
        $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $this->pledge->borrower_id,
            'date' => now()->toDateString(),
            'description' => 'Manual contribution',
            'type' => 'credit',
            'amount' => 1500.555,
        ])->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->assertDatabaseMissing('share_capital_ledger', ['borrower_id' => $this->pledge->borrower_id]);
    }

    public function test_a_ledger_entry_with_centavos_is_saved_exactly(): void
    {
        $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $this->pledge->borrower_id,
            'date' => now()->toDateString(),
            'description' => 'Manual contribution',
            'type' => 'credit',
            'amount' => 1500.55,
        ])->assertCreated();

        $this->assertDatabaseHas('share_capital_ledger', ['borrower_id' => $this->pledge->borrower_id, 'credit' => '1500.55']);
    }

    public function test_bulk_entries_with_centavos_are_saved_exactly(): void
    {
        $this->postJson('/api/pledges/bulk-entries', ['entries' => [[
            'pledge_id' => $this->pledge->id,
            'amount' => 250.05,
            'type' => 'credit',
            'date' => now()->toDateString(),
        ]]])->assertCreated()->assertJsonPath('count', 1);

        $this->assertDatabaseHas('share_capital_ledger', [
            'borrower_id' => $this->pledge->borrower_id,
            'credit' => '250.05',
        ]);
    }

    public function test_a_bulk_entry_with_more_than_two_decimals_is_refused_not_rounded(): void
    {
        $this->postJson('/api/pledges/bulk-entries', ['entries' => [[
            'pledge_id' => $this->pledge->id,
            'amount' => 250.055,
            'type' => 'credit',
            'date' => now()->toDateString(),
        ]]])->assertUnprocessable()->assertJsonValidationErrors(['entries.0.amount']);

        $this->assertDatabaseMissing('share_capital_ledger', ['borrower_id' => $this->pledge->borrower_id]);
    }
}
