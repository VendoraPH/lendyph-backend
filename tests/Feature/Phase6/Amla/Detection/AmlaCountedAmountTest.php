<?php

namespace Tests\Feature\Phase6\Amla\Detection;

use App\Models\AmlaAlert;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\GCashNonMember;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * AMLA-B8 review (PR #352): an alert's `counted_amount` and
 * `counted_transactions` are the figures its reason prints, set by each rule:
 * a rule whose reason prints no peso total stores none, and rapid movement
 * and loan anomalies store their headline figure, never money in plus money
 * out or a release plus its payments. G and L never raise an alert.
 */
class AmlaCountedAmountTest extends TestCase
{
    use DetectionTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->startDetection();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function assertCounted(string $code, ?Borrower $member, ?string $amount, ?int $count, ?int $walkInId = null): void
    {
        $alert = AmlaAlert::query()->where('rule_code', $code)
            ->when($member !== null, fn ($q) => $q->where('borrower_id', $member->id), fn ($q) => $q->where('gcash_non_member_id', $walkInId))->sole();

        $this->assertSame([$amount, $count], [$alert->counted_amount, $alert->counted_transactions], "rule {$code}");

        if ($amount !== null) {
            $this->assertStringContainsString('₱'.number_format((float) $amount, 2), $alert->reason, "rule {$code} prints its figure");
        }
    }

    public function test_each_rule_stores_the_figure_its_reason_prints(): void
    {
        $a = $this->member();
        $this->event($a, '300000.00');
        $this->event($a, '300000.00');

        $b = $this->member();
        foreach (range(1, 10) as $i) {
            $this->event($b, '1000.00', ['occurred_on' => '2026-11-'.(16 + $i % 5), 'channel' => 'bank_transfer']);
        }

        $c = $this->member();
        foreach (['2026-10-01', '2026-10-15', '2026-11-01'] as $day) {
            $this->event($c, '10000.00', ['occurred_on' => $day, 'channel' => 'bank_transfer']);
        }
        $this->event($c, '50000.00', ['channel' => 'bank_transfer']);

        $d = $this->member(attributes: ['monthly_income' => 40000]);
        $this->event($d, '120000.01', ['occurred_on' => '2026-11-03', 'channel' => 'bank_transfer']);

        $e = $this->member();
        $this->event($e, '500000.00', ['occurred_on' => '2026-11-18', 'channel' => 'bank_transfer']);
        $this->event($e, '490000.00', ['direction' => 'out', 'kind' => 'savings_withdrawal', 'channel' => 'bank_transfer']);

        $f = $this->member();
        foreach (range(1, 3) as $i) {
            $this->event($f, '10000.00', ['channel' => 'bank_transfer', 'occurred_on' => '2026-11-'.(16 + $i)]);
            $this->event($f, '10000.00', ['direction' => 'out', 'kind' => 'savings_withdrawal', 'channel' => 'bank_transfer', 'occurred_on' => '2026-11-'.(16 + $i)]);
        }

        $h = $this->member();
        foreach (range(1, 5) as $i) {
            $this->event($h, '1000.00', ['source' => 'gcash', 'kind' => 'gcash_cash_in']);
        }

        $i = $this->member();
        $this->event($i, '200000.00', ['source' => 'loan', 'kind' => 'loan_release', 'direction' => 'out', 'occurred_on' => '2026-11-10']);
        $this->event($i, '100000.00', ['source' => 'repayment', 'kind' => 'loan_payment']);

        $j = $this->member();
        foreach ([$this->branch->id, Branch::factory()->create()->id, Branch::factory()->create()->id] as $branchId) {
            $this->event($j, '1000.00', ['branch_id' => $branchId, 'channel' => 'bank_transfer']);
        }

        $walkIn = GCashNonMember::factory()->create(['id_type' => null, 'id_number' => null]);
        $this->event($this->member(), '50000.01', ['borrower_id' => null, 'gcash_non_member_id' => $walkIn->id, 'source' => 'gcash', 'kind' => 'gcash_cash_in']);

        $this->detect();

        $this->assertCounted('A', $a, '600000.00', 2);
        $this->assertCounted('B', $b, null, 10);
        $this->assertCounted('C', $c, '50000.00', 1);
        $this->assertCounted('D', $d, '120000.01', 1);
        $this->assertCounted('E', $e, '500000.00', 1);
        $this->assertCounted('F', $f, '60000.00', 6);
        $this->assertCounted('H', $h, '5000.00', 5);
        $this->assertCounted('I', $i, '100000.00', 1);
        $this->assertCounted('J', $j, null, null);
        $this->assertCounted('K', null, null, 1, $walkIn->id);
        $this->assertSame(0, AmlaAlert::query()->whereIn('rule_code', ['G', 'L'])->count());
    }
}
