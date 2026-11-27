<?php

namespace Tests\Feature;

use App\Models\AmortizationSchedule;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\Repayment;
use App\Models\RepaymentAllocation;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The payments page shows the preview's allocation and payment type, so the
 * preview has to say exactly what posting the same amount would record.
 *
 * The loan: ₱60,000 over six months at 3% straight (₱10,000 principal and
 * ₱1,800 interest a period), 2% penalty, 3 days grace, started 2026-01-15.
 */
class RepaymentPreviewTest extends TestCase
{
    use SetupLendyPH;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->travelTo(Carbon::parse('2026-01-15 09:00'));
        $this->loan = $this->createReleasedLoan(['start_date' => '2026-01-15']);
    }

    /**
     * @return array<string, array{string, float, string}>
     */
    public static function payments(): array
    {
        return [
            // ₱10,000 against period 1's ₱11,800, the case badged "Full Payment (with arrears)".
            'partial' => ['2026-02-10', 10000, 'partial'],
            'exact' => ['2026-02-10', 11800, 'exact'],
            // Periods 1 and 2 both late, each ₱11,800 plus a ₱200 penalty.
            'with arrears' => ['2026-03-20', 24000, 'exact'],
            'part of the arrears' => ['2026-03-20', 10000, 'partial'],
            // Period 1 late (₱200 penalty), and on into period 2 before it falls due.
            'advance' => ['2026-03-10', 23800, 'advance'],
        ];
    }

    #[DataProvider('payments')]
    public function test_the_preview_matches_what_the_payment_records(string $date, float $amount, string $badge): void
    {
        $this->travelTo(Carbon::parse("{$date} 09:00"));

        $preview = $this->postJson("/api/loans/{$this->loan->id}/repayments/preview", [
            'amount_paid' => $amount,
            'payment_date' => $date,
        ])->assertOk()->json('data');

        $id = $this->postJson("/api/loans/{$this->loan->id}/repayments", [
            'amount_paid' => $amount,
            'payment_date' => $date,
        ])->assertCreated()->json('data.id');
        $repayment = Repayment::findOrFail($id);

        $this->assertSame($badge, $preview['payment_badge']['type']);
        $this->assertSame($repayment->payment_type, $preview['payment_type']);
        $this->assertEqualsWithDelta((float) $repayment->principal_applied, $preview['total_principal'], 0.001);
        $this->assertEqualsWithDelta((float) $repayment->interest_applied, $preview['total_interest'], 0.001);
        $this->assertEqualsWithDelta((float) $repayment->penalty_applied, $preview['total_penalty'], 0.001);

        $recorded = RepaymentAllocation::where('repayment_id', $repayment->id)
            ->orderBy('period_number')
            ->get()
            ->map(fn (RepaymentAllocation $a) => [
                'schedule_id' => $a->amortization_schedule_id,
                'period' => $a->period_number,
                'penalty' => (float) $a->penalty,
                'interest' => (float) $a->interest,
                'principal' => (float) $a->principal,
            ])->all();

        $previewed = collect($preview['allocations'])
            ->map(fn (array $a) => [
                'schedule_id' => $a['schedule_id'],
                'period' => $a['period'],
                'penalty' => (float) $a['penalty'],
                'interest' => (float) $a['interest'],
                'principal' => (float) $a['principal'],
            ])->all();

        $this->assertSame($recorded, $previewed);
    }

    public function test_the_badge_labels_name_the_payment(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 09:00'));

        $this->postJson("/api/loans/{$this->loan->id}/repayments/preview", [
            'amount_paid' => 10000,
            'payment_date' => '2026-02-10',
        ])->assertOk()->assertJsonPath('data.payment_badge', ['type' => 'partial', 'label' => 'Partial Payment']);
    }

    public function test_each_previewed_period_says_what_it_would_still_owe(): void
    {
        $this->travelTo(Carbon::parse('2026-02-10 09:00'));

        $allocations = $this->postJson("/api/loans/{$this->loan->id}/repayments/preview", [
            'amount_paid' => 10000,
            'payment_date' => '2026-02-10',
        ])->assertOk()->json('data.allocations');

        $this->assertCount(1, $allocations);
        $this->assertSame('2026-02-15', $allocations[0]['due_date']);
        $this->assertEqualsWithDelta(10000, $allocations[0]['amount_applied'], 0.001);
        $this->assertEqualsWithDelta(1800, $allocations[0]['remaining_balance'], 0.001);
    }

    public function test_a_preview_saves_nothing(): void
    {
        $this->travelTo(Carbon::parse('2026-03-10 09:00'));
        $before = $this->snapshot();

        $this->postJson("/api/loans/{$this->loan->id}/repayments/preview", [
            'amount_paid' => 23800,
            'payment_date' => '2026-03-10',
        ])->assertOk();

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'repayments' => Repayment::count(),
            'allocations' => RepaymentAllocation::count(),
            'audit' => AuditLog::count(),
            'loan' => $this->loan->fresh()->toArray(),
            'periods' => AmortizationSchedule::where('loan_id', $this->loan->id)->orderBy('id')->get()->toArray(),
        ];
    }
}
