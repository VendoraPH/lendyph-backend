<?php

namespace Tests\Feature\Phase6\Amla\Screening;

use App\Models\AmlaScreeningMatch;
use App\Models\AmlaWatchlistEntry;
use App\Models\AmlaWatchlistVersion;
use App\Models\SavingsAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * AMLA-B8 follow-ups from checks S1 and S2: an order's release requests
 * carry the approver's note (to `amla:sanctions` holders only), and a
 * watchlist version uploaded without a label keeps no made-up "Uploaded
 * <time>" label, so screens and reports read "Version #n".
 */
class AmlaB8ScreeningFollowUpsTest extends TestCase
{
    use ScreeningTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow('2026-11-21 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_orders_release_requests_carry_the_approvers_note_to_sanctions_holders_only(): void
    {
        $member = $this->member();
        $account = SavingsAccount::factory()->create(['borrower_id' => $member->id, 'branch_id' => $member->branch_id, 'ledger_balance' => '1000.00']);
        $officer = $this->sanctionsOfficer();
        $id = $this->actingAs($officer)->postJson('/api/amla/orders', [
            'reference' => 'AMLC Freeze Order 2026-0050', 'authority' => 'amlc', 'received_on' => '2026-11-21', 'borrower_id' => $member->id,
            'required_action' => 'Freeze the funds.', 'responsible_officer_id' => $officer->id,
            'holds' => [['account_id' => $account->id, 'amount' => '1000.00']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');

        $checker = $this->sanctionsOfficer();
        $this->actingAs($officer)->postJson("/api/amla/orders/{$id}/release", ['reason' => 'Lifted.', 'release_reference' => 'R-1'])->assertStatus(202);
        $this->actingAs($checker)->postJson("/api/amla/orders/{$id}/release/reject", ['note' => 'No lifting order attached.'])->assertOk();
        $this->actingAs($officer)->postJson("/api/amla/orders/{$id}/release", ['reason' => 'AMLC lifted the freeze.', 'release_reference' => 'R-2'])->assertStatus(202);
        $this->actingAs($checker)->postJson("/api/amla/orders/{$id}/release/approve", ['note' => 'Resolution checked.'])->assertOk();

        $requests = $this->actingAs($officer)->getJson("/api/amla/orders/{$id}")->assertOk()->json('data.release_requests');
        $this->assertSame([
            ['rejected', 'R-1', 'No lifting order attached.', $officer->id, $checker->id],
            ['approved', 'R-2', 'Resolution checked.', $officer->id, $checker->id],
        ], array_map(fn (array $request) => [$request['status'], $request['release_reference'], $request['decision_note'], $request['requested_by']['id'], $request['decided_by']['id']], $requests));
        $this->assertSame('Approved', $requests[1]['status_words']);

        $this->actingAs($this->amlaUser(['amla:screening', 'branches:view_all']))->getJson("/api/amla/orders/{$id}")->assertForbidden();
        $this->actingAs($this->amlaUser(['amla:sanctions']))->getJson("/api/amla/orders/{$id}")->assertForbidden();
    }

    public function test_a_version_uploaded_without_a_label_stores_none_and_reads_version_n(): void
    {
        $screener = $this->screener();
        $csv = $this->csvList([['INT-1', 'individual', 'Listed Person', '', '', '']]);

        $version = $this->upload($screener, 'internal', $csv, 'internal.csv')->assertCreated()->json('data');
        $this->assertNull($version['version_label']);
        $this->assertNull(AmlaWatchlistVersion::query()->findOrFail($version['id'])->version_label);

        $labelled = $this->upload($screener, 'internal', $this->csvList([['INT-2', 'individual', 'Other Person', '', '', '']]), 'internal.csv', 'November internal list')->assertCreated()->json('data');
        $this->assertSame('November internal list', $labelled['version_label']);

        $entry = AmlaWatchlistEntry::query()->where('version_id', $version['id'])->sole();
        $member = $this->member();
        AmlaScreeningMatch::query()->create([
            'subject_type' => 'member', 'subject_id' => $member->id, 'entry_id' => $entry->id, 'version_id' => $version['id'],
            'source' => 'internal', 'match_key' => "member:{$member->id}:{$entry->id}", 'score' => 92, 'threshold' => 85,
            'reasons' => ['Name matches'], 'status' => AmlaScreeningMatch::PENDING,
        ]);

        $rows = $this->actingAs($screener)->getJson('/api/amla/compliance-reports/watchlist_screening?from=2026-11-01&to=2026-11-30')->assertOk()->json('data.rows');
        $this->assertSame(["Version #{$version['id']}"], array_column($rows, 'list_version'));
    }
}
