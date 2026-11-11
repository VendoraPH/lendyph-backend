<?php

namespace Tests\Feature\Portal\Read;

use App\Services\RepaymentService;
use App\Services\Savings\SavingsMath;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A member's loans in the portal (portal design §1, §3): only their own
 * ever-released loans, never one where they are only a co-maker and never a
 * draft, and every figure the staff loan screens show for the same loan.
 */
class PortalLoanReadsTest extends TestCase
{
    use PortalReadFixtures, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_the_list_holds_only_the_members_own_released_loans(): void
    {
        $me = $this->portalMember();
        $other = $this->portalMember();
        $mine = $this->releasedLoanFor($me);
        $this->draftLoanFor($me);
        $coMade = $this->coMakerLoan($me, $other);

        $response = $this->portalJson('GET', '/api/portal/loans', [], $this->portalToken($me));

        $response->assertOk();
        $this->assertSame([$mine->id], array_column($response->json('data'), 'id'));

        $this->portalJson('GET', "/api/portal/loans/{$coMade->id}", [], $this->portalToken($me))->assertNotFound();
        $this->portalJson('GET', "/api/portal/loans/{$coMade->id}/schedule", [], $this->portalToken($me))->assertNotFound();
    }

    public function test_a_draft_answers_404(): void
    {
        $me = $this->portalMember();
        $draft = $this->draftLoanFor($me);

        $this->portalJson('GET', "/api/portal/loans/{$draft->id}", [], $this->portalToken($me))->assertNotFound();
    }

    public function test_every_list_and_detail_figure_equals_the_staff_loan_summary(): void
    {
        $me = $this->portalMember();
        $loan = $this->releasedLoanFor($me);
        $this->pay($loan, 5000);

        $staff = $this->staffGet("/api/loans/{$loan->id}/summary")->json('data');
        $token = $this->portalToken($me);
        $row = $this->portalJson('GET', '/api/portal/loans', [], $token)->assertOk()->json('data.0');
        $detail = $this->portalJson('GET', "/api/portal/loans/{$loan->id}", [], $token)->assertOk()->json('data');

        foreach (['total_outstanding', 'outstanding_principal', 'overdue_amount', 'next_due_amount'] as $key) {
            $this->assertSame($this->pesos($staff[$key]), $row[$key], "list {$key}");
            $this->assertSame($this->pesos($staff[$key]), $detail[$key], "detail {$key}");
        }

        foreach (['outstanding_interest', 'outstanding_penalty', 'insurance_owed', 'total_repaid', 'principal_paid', 'interest_paid', 'penalty_paid'] as $key) {
            $this->assertSame($this->pesos($staff[$key]), $detail[$key], "detail {$key}");
        }

        $this->assertSame($staff['next_due_date'], $row['next_due_date']);
        $this->assertSame($this->pesos($staff['principal_amount']), $row['principal_amount']);
        $this->assertSame('past_due', $row['display_status'], 'Periods three months back are late.');
        $this->assertSame((int) floor($staff['principal_paid'] * 100 / $staff['principal_amount']), $row['paid_percent']);

        foreach (['advance_presets', 'payoff_amount', 'restructurable_balance', 'outstanding_balance'] as $key) {
            $this->assertArrayNotHasKey($key, $detail);
        }
    }

    public function test_the_schedule_equals_the_amortization_balance_tab(): void
    {
        $me = $this->portalMember();
        $loan = $this->releasedLoanFor($me);
        $this->pay($loan, 15000);

        $staff = $this->staffGet("/api/loans/{$loan->id}/amortization-balances")->json('data');
        $mine = $this->portalJson('GET', "/api/portal/loans/{$loan->id}/schedule", [], $this->portalToken($me))->assertOk()->json('data');

        $this->assertCount(count($staff['periods']), $mine['periods']);

        foreach ($staff['periods'] as $i => $period) {
            $row = $mine['periods'][$i];
            $this->assertSame($period['period_number'], $row['period_number']);
            $this->assertSame($period['due_date'], $row['due_date']);
            $this->assertSame($this->pesos($period['balance']), $row['balance']);

            foreach (['principal', 'interest'] as $part) {
                foreach (['due', 'paid', 'balance'] as $figure) {
                    $this->assertSame($this->pesos($period[$part][$figure]), $row[$part][$figure], "{$part}.{$figure} of period {$period['period_number']}");
                }
            }
        }

        foreach (['insurance_owed', 'balance', 'overdue', 'paid'] as $key) {
            $this->assertSame($this->pesos($staff['totals'][$key]), $mine['totals'][$key], "totals.{$key}");
        }

        $statuses = array_column($mine['periods'], 'status');
        $this->assertContains('paid', $statuses, 'The payment settled the first period.');
        $this->assertContains('past_due', $statuses);
        $this->assertContains('upcoming', $statuses);
        $this->assertEmpty(array_diff($statuses, ['paid', 'partial', 'due', 'past_due', 'upcoming', 'closed']));
    }

    public function test_payments_equal_the_staff_repayments_and_leave_out_voided_ones(): void
    {
        $me = $this->portalMember();
        $loan = $this->releasedLoanFor($me);
        $kept = $this->pay($loan, 5000)->json('data');
        $voided = $this->pay($loan, 3000)->json('data');
        $this->actingAs($this->admin)->patchJson("/api/repayments/{$voided['id']}/void", ['void_reason' => 'Wrong amount entered'])->assertOk();

        $token = $this->portalToken($me);
        $perLoan = $this->portalJson('GET', "/api/portal/loans/{$loan->id}/payments", [], $token)->assertOk()->json('data');
        $all = $this->portalJson('GET', '/api/portal/payments', [], $token)->assertOk()->json('data');

        $this->assertSame([$kept['receipt_number']], array_column($perLoan, 'receipt_number'));
        $this->assertSame([$kept['receipt_number']], array_column($all, 'receipt_number'));

        $row = $perLoan[0];
        $this->assertSame($this->pesos($kept['amount_paid']), $row['amount_paid']);
        $this->assertSame($this->pesos($kept['principal_applied']), $row['principal']);
        $this->assertSame($this->pesos($kept['interest_applied']), $row['interest']);
        $this->assertSame($this->pesos($kept['penalty_applied']), $row['penalty']);
        $this->assertSame($this->pesos($kept['insurance_applied']), $row['insurance']);
        $this->assertSame('cash', $row['method']);
        $this->assertSame($loan->loan_account_number, $all[0]['loan_account_number']);
    }

    public function test_penalty_months_equal_the_staff_summary_and_schedule(): void
    {
        $me = $this->portalMember();
        $loan = $this->releasedLoanFor($me);
        app(RepaymentService::class)->applyPenalties($loan->fresh(), now());

        $summary = $this->staffGet("/api/loans/{$loan->id}/summary")->json('data');
        $staffRows = collect($this->staffGet("/api/loans/{$loan->id}/amortization-schedule")->json('data'))->keyBy('period_number');
        $token = $this->portalToken($me);
        $row = $this->portalJson('GET', '/api/portal/loans', [], $token)->assertOk()->json('data.0');
        $detail = $this->portalJson('GET', "/api/portal/loans/{$loan->id}", [], $token)->assertOk()->json('data');
        $periods = $this->portalJson('GET', "/api/portal/loans/{$loan->id}/schedule", [], $token)->assertOk()->json('data.periods');

        $this->assertGreaterThan(0, $summary['penalty_months'], 'Periods three months back carry monthly charges.');
        $this->assertSame($summary['penalty_months'], $row['penalty_months']);
        $this->assertSame($summary['penalty_months'], $detail['penalty_months']);

        foreach ($periods as $period) {
            $this->assertSame($staffRows[$period['period_number']]['penalty_months'], $period['penalty_months'], "period {$period['period_number']}");
        }
    }

    public function test_loans_switched_off_answer_403_in_words(): void
    {
        $this->enablePortal(['features' => ['loans' => false]]);
        $me = $this->portalMember();

        $this->portalJson('GET', '/api/portal/loans', [], $this->portalToken($me))
            ->assertForbidden()
            ->assertJsonPath('code', 'feature_off');
    }

    private function pesos(float|int|string|null $amount): string
    {
        return SavingsMath::pesos((int) round((float) $amount * 100));
    }
}
