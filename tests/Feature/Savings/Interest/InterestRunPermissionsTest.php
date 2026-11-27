<?php

namespace Tests\Feature\Savings\Interest;

use App\Models\SavingsInterestRun;
use App\Models\SavingsProduct;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Each interest-run endpoint against a role holding exactly the permissions
 * it needs (never super_admin): `savings:view` reads, `savings:post`
 * previews, creates and posts, `savings:approve` approves, rejects and
 * reverses (design §2).
 */
class InterestRunPermissionsTest extends TestCase
{
    use SavingsHistory, SetupLendyPH;

    private SavingsProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->cooperativeBooks();
        $this->onDay('2026-07-01');
        $this->product = $this->regularProduct();
        $this->openAccount($this->product, 1_000_000);
        $this->onDay('2026-10-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function draft(string $start = '2026-07-01', string $end = '2026-09-30', string $status = 'draft'): SavingsInterestRun
    {
        return SavingsInterestRun::factory()->create([
            'product_id' => $this->product->id,
            'branch_id' => $this->branch->id,
            'period_start' => $start,
            'period_end' => $end,
            'status' => $status,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_reading_needs_savings_view(): void
    {
        $run = $this->draft();

        $this->actingAs($this->userWith(['reports:view']))->getJson('/api/savings/interest-runs')->assertForbidden();
        $this->actingAs($this->userWith(['reports:view']))->getJson("/api/savings/interest-runs/{$run->id}")->assertForbidden();

        $viewer = $this->userWith(['savings:view']);
        $this->actingAs($viewer)->getJson('/api/savings/interest-runs')->assertOk()->assertJsonPath('meta.stats.draft', 1);
        $this->actingAs($viewer)->getJson("/api/savings/interest-runs/{$run->id}")->assertOk()->assertJsonPath('data.id', $run->id);
    }

    public function test_preview_and_create_need_savings_post(): void
    {
        $body = ['product_id' => $this->product->id, 'period_start' => '2026-07-01'];
        $without = $this->userWith(['savings:view', 'savings:approve']);
        $poster = $this->userWith(['savings:post']);

        $this->actingAs($without)->postJson('/api/savings/interest-runs/preview', $body)->assertForbidden();
        $this->actingAs($without)->postJson('/api/savings/interest-runs', $body)->assertForbidden();

        $this->actingAs($poster)->postJson('/api/savings/interest-runs/preview', $body)->assertOk();
        $this->actingAs($poster)->postJson('/api/savings/interest-runs', $body)->assertCreated();
    }

    public function test_approve_and_reject_need_savings_approve(): void
    {
        $run = $this->draft();
        $without = $this->userWith(['savings:view', 'savings:post']);
        $checker = $this->userWith(['savings:approve']);

        $this->actingAs($without)->postJson("/api/savings/interest-runs/{$run->id}/approve")->assertForbidden();
        $this->actingAs($without)->postJson("/api/savings/interest-runs/{$run->id}/reject", ['reason' => 'No'])->assertForbidden();

        $this->actingAs($checker)->postJson("/api/savings/interest-runs/{$run->id}/approve")->assertOk();

        $other = $this->draft('2026-04-01', '2026-06-30');
        $this->actingAs($checker)->postJson("/api/savings/interest-runs/{$other->id}/reject", ['reason' => 'Not needed'])->assertOk();
    }

    public function test_posting_needs_savings_post(): void
    {
        $poster = $this->userWith(['savings:post']);
        $id = $this->actingAs($poster)->postJson('/api/savings/interest-runs', ['product_id' => $this->product->id, 'period_start' => '2026-07-01'])->json('data.0.id');
        $this->actingAs($this->userWith(['savings:approve']))->postJson("/api/savings/interest-runs/{$id}/approve")->assertOk();

        $this->actingAs($this->userWith(['savings:view', 'savings:approve']))->postJson("/api/savings/interest-runs/{$id}/post")->assertForbidden();
        $this->actingAs($poster)->postJson("/api/savings/interest-runs/{$id}/post")->assertOk();
    }

    public function test_reversing_needs_savings_approve(): void
    {
        $poster = $this->userWith(['savings:post']);
        $id = $this->actingAs($poster)->postJson('/api/savings/interest-runs', ['product_id' => $this->product->id, 'period_start' => '2026-07-01'])->json('data.0.id');
        $checker = $this->userWith(['savings:approve']);
        $this->actingAs($checker)->postJson("/api/savings/interest-runs/{$id}/approve")->assertOk();
        $this->actingAs($poster)->postJson("/api/savings/interest-runs/{$id}/post")->assertOk();

        $this->actingAs($poster)->postJson("/api/savings/interest-runs/{$id}/reverse", ['reason' => 'Wrong'])->assertForbidden();
        $this->actingAs($checker)->postJson("/api/savings/interest-runs/{$id}/reverse", ['reason' => 'Wrong'])->assertOk();
    }
}
