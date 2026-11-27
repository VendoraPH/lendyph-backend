<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Services\RepaymentService;
use ErrorException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The payment preview says how its excess will be credited. It sends
 * `build_up_credited` and `overpayment_kept` as 2-decimal strings, from the
 * share capital credit the payment itself writes inside the rolled-back
 * preview: the same code path recording the payment takes. That path covers
 * the loan's `scb_amount` and the member's status, since a pending or rejected
 * member gets no build-up credit. The preview figures equal the recorded
 * payment's. `excess` is unchanged. Display only.
 */
class RepaymentPreviewBuildUpSplitTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** A released loan, with a build-up or not, whose member has this status. */
    private function loan(bool $buildUp, string $memberStatus = 'active'): Loan
    {
        $loan = $this->createReleasedLoan();

        if ($buildUp) {
            $loan->update(['scb_amount' => 500]);
        }

        DB::table('borrowers')->where('id', $loan->borrower_id)->update(['status' => $memberStatus]);

        return $loan->fresh();
    }

    /** @return array<string, array{0: bool, 1: string, 2: float, 3: array{0: string, 1: string}}> */
    public static function cases(): array
    {
        return [
            'a build-up loan, active member' => [true, 'active', 750.0, ['750.00', '0.00']],
            'a build-up loan, pending member' => [true, 'pending', 750.0, ['0.00', '750.00']],
            'a build-up loan, rejected member' => [true, 'rejected', 750.0, ['0.00', '750.00']],
            'a non-build-up loan' => [false, 'active', 300.0, ['0.00', '300.00']],
            'an exact payment' => [true, 'active', 0.0, ['0.00', '0.00']],
        ];
    }

    /** @param  array{0: string, 1: string}  $expected */
    #[DataProvider('cases')]
    public function test_the_preview_split_equals_the_recorded_payment(bool $buildUp, string $memberStatus, float $extra, array $expected): void
    {
        $loan = $this->loan($buildUp, $memberStatus);
        $body = [
            'payment_date' => now()->toDateString(),
            // A build-up loan's extra past the first period is the build-up; a
            // loan with none carries it on to the next period, so only a full
            // payoff leaves an overpayment.
            'amount_paid' => round((float) ($buildUp ? $loan->amortizationSchedules->first()->total_due : $loan->amortizationSchedules->sum('total_due')) + $extra, 2),
        ];

        $preview = $this->postJson("/api/loans/{$loan->id}/repayments/preview", $body)->assertOk()->json('data');
        $this->assertSame($expected, [$preview['build_up_credited'], $preview['overpayment_kept']], 'The preview');
        $this->assertEquals($extra, $preview['excess'], '`excess` is unchanged.');

        $id = $this->postJson("/api/loans/{$loan->id}/repayments", [...$body, 'method' => 'cash'])->assertCreated()->json('data.id');
        $recorded = $this->getJson("/api/repayments/{$id}")->assertOk()->json('data');

        $this->assertSame(
            [$recorded['build_up_credited'], $recorded['overpayment_kept']],
            [$preview['build_up_credited'], $preview['overpayment_kept']],
            'The preview and the recorded payment give the same split.',
        );
    }

    public function test_a_preview_writes_no_share_capital(): void
    {
        $loan = $this->loan(buildUp: true);

        $this->postJson("/api/loans/{$loan->id}/repayments/preview", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => round((float) $loan->amortizationSchedules->first()->total_due + 750, 2),
        ])->assertOk()->assertJsonPath('data.build_up_credited', '750.00');

        $this->assertDatabaseCount('share_capital_ledger', 0);
        $this->assertDatabaseCount('repayments', 0);
    }

    /** A build-up payment with ₱750.00 extra by a member now pending, recorded at $recordedAt (Manila). */
    private function pendingMemberPaymentRecordedAt(string $recordedAt): int
    {
        $loan = $this->loan(buildUp: true, memberStatus: 'pending');
        $id = $this->postJson("/api/loans/{$loan->id}/repayments", [
            'payment_date' => now()->toDateString(),
            'amount_paid' => round((float) $loan->amortizationSchedules->first()->total_due + 750, 2),
            'method' => 'cash',
        ])->assertCreated()->json('data.id');
        DB::table('repayments')->where('id', $id)->update(['created_at' => $recordedAt]);

        return $id;
    }

    /** @return array<string, array{0: string, 1: array{0: string|null, 1: string|null}}> */
    public static function recordedAt(): array
    {
        return [
            'before member status edits were locked' => ['2026-11-06 10:00:00', [null, null]],
            'on the day the lock was merged (22:45 Manila)' => ['2026-11-07 21:00:00', [null, null]],
            'after the lock' => ['2026-11-08 00:00:00', ['0.00', '750.00']],
        ];
    }

    /**
     * Until #226 (merged 2026-11-07 22:45 Manila) a member edit could set
     * `status`, so a member who is pending or rejected now may have been
     * active when an older payment was made, before the automatic credit.
     * Only a payment recorded once status edits were locked proves that the
     * member couldn't be credited.
     *
     * @param  array{0: string|null, 1: string|null}  $expected
     */
    #[DataProvider('recordedAt')]
    public function test_a_pending_members_payment_reads_no_build_up_only_once_status_edits_were_locked(string $recordedAt, array $expected): void
    {
        $id = $this->pendingMemberPaymentRecordedAt($recordedAt);

        $detail = $this->getJson("/api/repayments/{$id}")->assertOk()->json('data');
        $this->assertSame($expected, [$detail['build_up_credited'], $detail['overpayment_kept']]);
    }

    public function test_the_credit_rule_reads_the_member_as_recording_always_has(): void
    {
        $loan = $this->loan(buildUp: true);
        $loan->setRelation('borrower', null);

        $this->expectException(ErrorException::class);
        RepaymentService::creditsBuildUp($loan);
    }
}
