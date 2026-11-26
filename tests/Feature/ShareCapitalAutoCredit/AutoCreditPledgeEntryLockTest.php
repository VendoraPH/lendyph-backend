<?php

namespace Tests\Feature\ShareCapitalAutoCredit;

use App\Models\Borrower;
use App\Models\ShareCapitalLedger;
use App\Services\ShareCapitalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Finish run round 7: a pledge set to auto-credit takes no entry by hand.
 * The auto-credit run credits it, so a hand entry could credit a month twice.
 * Pledge Entry locked the row on screen only; the server now refuses, in
 * words, a single entry or a whole bulk batch holding one, credit or debit,
 * with nothing posted, on Pledge Entry's single entry (POST
 * /share-capital/ledger) as on POST /pledges/{pledge}/entries.
 */
class AutoCreditPledgeEntryLockTest extends TestCase
{
    use SetupLendyPH;

    private Borrower $ana;

    private Borrower $ben;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'Asia/Manila'));

        $this->ana = Borrower::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Ana', 'middle_name' => null, 'last_name' => 'Santos']);
        $this->ben = Borrower::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Ben', 'middle_name' => null, 'last_name' => 'Cruz']);
        $this->ana->shareCapitalPledge->update(['amount' => 500, 'auto_credit' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_single_credit_on_an_auto_credit_pledge_is_refused_in_words_with_nothing_posted(): void
    {
        $this->postJson("/api/pledges/{$this->ana->shareCapitalPledge->id}/entries", $this->entry('credit'))
            ->assertUnprocessable()
            ->assertJsonPath('errors.pledge_id.0', ShareCapitalService::autoCreditPledgeRefusal($this->ana->full_name));

        $this->assertSame(0, ShareCapitalLedger::query()->count());
        $this->assertSame(0, DB::table('cash_reference_sequences')->count());
    }

    public function test_a_single_debit_on_an_auto_credit_pledge_is_refused_too(): void
    {
        $this->postJson("/api/pledges/{$this->ana->shareCapitalPledge->id}/entries", $this->entry('debit'))
            ->assertUnprocessable()
            ->assertJsonPath('message', ShareCapitalService::autoCreditPledgeRefusal($this->ana->full_name));

        $this->assertSame(0, ShareCapitalLedger::query()->count());
    }

    public function test_the_refusal_names_the_member_and_how_to_record_by_hand(): void
    {
        $this->assertSame(
            "Ana Santos's pledge is set to auto-credit, so the auto-credit run credits it. Turn auto-credit off for this pledge to record an entry by hand.",
            ShareCapitalService::autoCreditPledgeRefusal($this->ana->full_name),
        );
    }

    public function test_a_pledge_without_auto_credit_still_takes_an_entry(): void
    {
        $this->postJson("/api/pledges/{$this->ben->shareCapitalPledge->id}/entries", $this->entry('credit'))->assertCreated();

        $this->assertSame(1, ShareCapitalLedger::query()->count());
    }

    public function test_a_bulk_batch_holding_an_auto_credit_pledge_is_refused_whole_naming_it(): void
    {
        $carla = Borrower::factory()->create(['branch_id' => $this->branch->id, 'first_name' => 'Carla', 'middle_name' => null, 'last_name' => 'Reyes']);
        $carla->shareCapitalPledge->update(['amount' => 300, 'auto_credit' => true]);

        $this->postJson('/api/pledges/bulk-entries', ['entries' => [
            ['pledge_id' => $this->ben->shareCapitalPledge->id, 'amount' => 100, 'type' => 'credit', 'date' => '2026-11-24'],
            ['pledge_id' => $this->ana->shareCapitalPledge->id, 'amount' => 100, 'type' => 'credit', 'date' => '2026-11-24'],
            ['pledge_id' => $carla->shareCapitalPledge->id, 'amount' => 100, 'type' => 'debit', 'date' => '2026-11-24'],
        ]])->assertUnprocessable()
            ->assertJsonPath('errors.entries.0', ShareCapitalService::autoCreditBatchRefusal(['Ana Santos', 'Carla Reyes']));

        $this->assertSame(0, ShareCapitalLedger::query()->count());
        $this->assertSame(0, DB::table('cash_reference_sequences')->count());
    }

    public function test_the_batch_refusal_reads_in_words(): void
    {
        $this->assertSame(
            'These pledges are set to auto-credit, so the auto-credit run credits them: Ana Santos and Carla Reyes. Take them out of the batch, or turn auto-credit off for them, to record entries by hand.',
            ShareCapitalService::autoCreditBatchRefusal(['Ana Santos', 'Carla Reyes']),
        );
        $this->assertSame(
            'This pledge is set to auto-credit, so the auto-credit run credits it: Ana Santos. Take it out of the batch, or turn auto-credit off for it, to record entries by hand.',
            ShareCapitalService::autoCreditBatchRefusal(['Ana Santos']),
        );
    }

    public function test_a_batch_without_auto_credit_pledges_still_posts(): void
    {
        $this->postJson('/api/pledges/bulk-entries', ['entries' => [
            ['pledge_id' => $this->ben->shareCapitalPledge->id, 'amount' => 100, 'type' => 'credit', 'date' => '2026-11-24'],
        ]])->assertCreated();

        $this->assertSame(1, ShareCapitalLedger::query()->count());
    }

    public function test_turning_auto_credit_off_lets_the_entry_through(): void
    {
        $this->patchJson("/api/pledges/{$this->ana->shareCapitalPledge->id}/auto-credit")->assertOk()->assertJsonPath('auto_credit', false);

        $this->postJson("/api/pledges/{$this->ana->shareCapitalPledge->id}/entries", $this->entry('credit'))->assertCreated();
    }

    public function test_pledge_entrys_single_entry_through_the_ledger_is_refused_on_the_member(): void
    {
        // Pledge Entry's own single entry posts to POST /share-capital/ledger.
        foreach (['credit', 'debit'] as $type) {
            $this->postJson('/api/share-capital/ledger', [
                'borrower_id' => $this->ana->id,
                'date' => '2026-11-24',
                'description' => 'Manual '.$type.' entry',
                'type' => $type,
                'amount' => 500,
            ])->assertUnprocessable()
                ->assertJsonPath('errors.borrower_id.0', ShareCapitalService::autoCreditPledgeRefusal('Ana Santos'));
        }

        $this->assertSame(0, ShareCapitalLedger::query()->count());
        $this->assertSame(0, DB::table('cash_reference_sequences')->count());
    }

    public function test_a_ledger_entry_for_a_member_without_auto_credit_is_unchanged(): void
    {
        $this->postJson('/api/share-capital/ledger', [
            'borrower_id' => $this->ben->id,
            'date' => '2026-11-24',
            'description' => 'Contribution',
            'type' => 'credit',
            'amount' => 1000,
        ])->assertCreated();

        $this->assertSame(1, ShareCapitalLedger::query()->count());
    }

    /** @return array<string, mixed> */
    private function entry(string $type): array
    {
        return ['amount' => 500, 'type' => $type, 'date' => '2026-11-24'];
    }
}
