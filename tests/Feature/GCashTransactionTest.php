<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Borrower;
use App\Models\GCashTier;
use App\Models\GCashTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class GCashTransactionTest extends TestCase
{
    use SetupLendyPH;

    private Borrower $borrower;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->borrower = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->seedTiers();
    }

    private function seedTiers(): void
    {
        GCashTier::create(['min_amount' => 1, 'max_amount' => 1500, 'cash_in_rate' => 20, 'cash_out_rate' => 15, 'display_order' => 1]);
        GCashTier::create(['min_amount' => 1501, 'max_amount' => 5000, 'cash_in_rate' => 30, 'cash_out_rate' => 25, 'display_order' => 2]);
    }

    public function test_create_cash_in_with_paid_status_by_default(): void
    {
        $response = $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->borrower->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'cash_in')
            ->assertJsonPath('data.status', 'paid');

        $this->assertEquals(20.0, (float) $response->json('data.charge_amount'));
        $this->assertEquals(1020.0, (float) $response->json('data.total_amount'));
        $this->assertStringStartsWith('GC-', $response->json('data.reference_no'));
    }

    public function test_a_cash_in_recorded_as_paid_is_stamped_paid_when_it_is_recorded(): void
    {
        $this->travelTo(Carbon::parse('2026-10-03 14:25:36'));

        $id = $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->borrower->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'paid')
            ->json('data.id');

        $tx = GCashTransaction::findOrFail($id);
        $this->assertSame('2026-10-03 14:25:36', $tx->paid_at?->toDateTimeString());
        $this->assertTrue($tx->paid_at->equalTo($tx->transaction_date));
        $this->assertNull($tx->paid_by_user_id);

        $created = AuditLog::where('auditable_type', GCashTransaction::class)->where('auditable_id', $id)->where('action', 'created')->sole();
        $this->assertNotNull($created->new_values['paid_at']);
    }

    public function test_a_pending_cash_in_and_a_cash_out_have_no_paid_at(): void
    {
        $pending = $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->borrower->id,
            'type' => 'cash_in',
            'amount' => 1000,
            'is_pending' => true,
        ])->assertCreated()->json('data.id');

        $cashOut = $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->borrower->id,
            'type' => 'cash_out',
            'amount' => 3000,
        ])->assertCreated()->json('data.id');

        $this->assertNull(GCashTransaction::findOrFail($pending)->paid_at);
        $this->assertNull(GCashTransaction::findOrFail($cashOut)->paid_at);
    }

    public function test_create_cash_in_pending_when_is_pending_true(): void
    {
        $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->borrower->id,
            'type' => 'cash_in',
            'amount' => 1000,
            'is_pending' => true,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_create_cash_out_uses_subtractive_total_and_completed_status(): void
    {
        $response = $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->borrower->id,
            'type' => 'cash_out',
            'amount' => 3000,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $this->assertEquals(25.0, (float) $response->json('data.charge_amount'));
        $this->assertEquals(2975.0, (float) $response->json('data.total_amount'));
    }

    public function test_create_returns_422_with_tier_message_when_amount_out_of_range(): void
    {
        $response = $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->borrower->id,
            'type' => 'cash_in',
            'amount' => 50000,
        ]);

        $response->assertUnprocessable();
        $errors = $response->json('errors') ?? [];
        $this->assertStringContainsString(
            'tier',
            strtolower(json_encode($errors).' '.($response->json('message') ?? '')),
        );
    }

    public function test_create_returns_409_on_duplicate_within_60_seconds(): void
    {
        $payload = [
            'borrower_id' => $this->borrower->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ];

        $this->postJson('/api/gcash/transactions', $payload)->assertCreated();
        $this->postJson('/api/gcash/transactions', $payload)->assertStatus(409);
    }

    public function test_reference_no_increments_per_day(): void
    {
        $a = $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->borrower->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ])->json('data.reference_no');

        $b = $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->borrower->id,
            'type' => 'cash_in',
            'amount' => 2000,
        ])->json('data.reference_no');

        $this->assertNotSame($a, $b);
        $datePart = now()->format('Ymd');
        $this->assertStringContainsString("GC-{$datePart}-", $a);
        $this->assertStringContainsString("GC-{$datePart}-", $b);
    }

    public function test_mark_paid_flips_pending_cash_in_to_paid(): void
    {
        $pending = $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->borrower->id,
            'type' => 'cash_in',
            'amount' => 1000,
            'is_pending' => true,
        ])->json('data');

        $response = $this->patchJson("/api/gcash/transactions/{$pending['id']}/paid");

        $response->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertNotNull($response->json('data.paid_at'));
        $this->assertNotNull($response->json('data.paid_by_user_id'));
    }

    public function test_mark_paid_rejects_already_paid_transaction(): void
    {
        $tx = $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->borrower->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ])->json('data');

        $this->patchJson("/api/gcash/transactions/{$tx['id']}/paid")
            ->assertUnprocessable();
    }

    public function test_mark_paid_rejects_cash_out(): void
    {
        $tx = $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->borrower->id,
            'type' => 'cash_out',
            'amount' => 3000,
        ])->json('data');

        $this->patchJson("/api/gcash/transactions/{$tx['id']}/paid")
            ->assertUnprocessable();
    }

    public function test_index_filters_by_type(): void
    {
        $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->borrower->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ])->assertCreated();
        $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->borrower->id,
            'type' => 'cash_out',
            'amount' => 3000,
        ])->assertCreated();

        $cashIns = $this->getJson('/api/gcash/transactions?type=cash_in');
        $cashIns->assertOk();
        $this->assertCount(1, $cashIns->json('data'));
        $this->assertEquals('cash_in', $cashIns->json('data.0.type'));
    }

    public function test_viewer_can_list_but_cannot_create(): void
    {
        GCashTransaction::factory()->create([
            'borrower_id' => $this->borrower->id,
            'transactor_user_id' => $this->admin->id,
        ]);

        $viewer = User::factory()->create();
        $viewer->assignRole('viewer');
        $this->actingAs($viewer);

        $this->getJson('/api/gcash/transactions')->assertOk();
        $this->postJson('/api/gcash/transactions', [
            'borrower_id' => $this->borrower->id,
            'type' => 'cash_in',
            'amount' => 1000,
        ])->assertForbidden();
    }
}
