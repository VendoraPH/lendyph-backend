<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

class AutoPayTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    private function enableAutoPay(Loan $loan, string $reference = 'CBS-2026-00001'): Loan
    {
        $loan->update([
            'auto_pay' => true,
            'cbs_reference' => $reference,
            'auto_pay_enabled_at' => now(),
            'auto_pay_enabled_by' => $this->admin->id,
        ]);

        return $loan->refresh();
    }

    public function test_toggle_enables_auto_pay_with_cbs_reference(): void
    {
        $loan = $this->createReleasedLoan();

        $response = $this->patchJson("/api/loans/{$loan->id}/auto-pay", [
            'enabled' => true,
            'cbs_reference' => 'CBS-2026-00123',
        ])->assertOk();

        $response->assertJsonPath('data.loan_id', $loan->id);
        $response->assertJsonPath('data.auto_pay_enabled', true);
        $response->assertJsonPath('data.cbs_reference', 'CBS-2026-00123');
        $response->assertJsonPath('data.enabled_by_user_id', $this->admin->id);
        $this->assertNotNull($response->json('data.enabled_at'));

        $loan->refresh();
        $this->assertTrue((bool) $loan->auto_pay);
        $this->assertSame('CBS-2026-00123', $loan->cbs_reference);
    }

    public function test_toggle_rejects_enable_without_cbs_reference(): void
    {
        $loan = $this->createReleasedLoan();

        $this->patchJson("/api/loans/{$loan->id}/auto-pay", [
            'enabled' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['cbs_reference']);
    }

    public function test_toggle_disable_clears_reference_and_audit_columns(): void
    {
        $loan = $this->enableAutoPay($this->createReleasedLoan());

        $this->patchJson("/api/loans/{$loan->id}/auto-pay", [
            'enabled' => false,
        ])->assertOk()
            ->assertJsonPath('data.auto_pay_enabled', false)
            ->assertJsonPath('data.cbs_reference', null)
            ->assertJsonPath('data.enabled_at', null)
            ->assertJsonPath('data.enabled_by_user_id', null);

        $loan->refresh();
        $this->assertFalse((bool) $loan->auto_pay);
        $this->assertNull($loan->cbs_reference);
    }

    public function test_toggle_rejects_when_loan_status_not_eligible(): void
    {
        $loan = $this->createReleasedLoan();
        $loan->update(['status' => 'completed']);

        $this->patchJson("/api/loans/{$loan->id}/auto-pay", [
            'enabled' => true,
            'cbs_reference' => 'CBS-2026-00123',
        ])->assertUnprocessable();
    }

    public function test_toggle_forbidden_for_user_without_permission(): void
    {
        $loan = $this->createReleasedLoan();

        $collector = User::factory()->create();
        $collector->assignRole(Role::where('name', 'collector')->first());
        $this->actingAs($collector);

        $this->patchJson("/api/loans/{$loan->id}/auto-pay", [
            'enabled' => true,
            'cbs_reference' => 'CBS-2026-00123',
        ])->assertForbidden();
    }

    public function test_preview_returns_summary_and_partial_rows_shape(): void
    {
        $loan = $this->enableAutoPay($this->createReleasedLoan());
        $firstSchedule = $loan->amortizationSchedules->first();
        $dueDate = $firstSchedule->due_date->toDateString();

        $response = $this->getJson('/api/auto-pay/preview?'.http_build_query([
            'date_from' => $dueDate,
            'date_to' => $dueDate,
        ]))->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'summary' => ['total_principal', 'total_interest', 'total_amount', 'loans_count'],
                    'partial_rows',
                ],
            ]);

        $this->assertSame(1, $response->json('data.summary.loans_count'));
        $this->assertGreaterThan(0, $response->json('data.summary.total_amount'));
    }

    public function test_preview_excludes_loans_with_auto_pay_disabled(): void
    {
        $loan = $this->createReleasedLoan();
        $dueDate = $loan->amortizationSchedules->first()->due_date->toDateString();

        $response = $this->getJson('/api/auto-pay/preview?'.http_build_query([
            'date_from' => $dueDate,
            'date_to' => $dueDate,
        ]))->assertOk();

        $this->assertSame(0, $response->json('data.summary.loans_count'));
        $this->assertEquals([], $response->json('data.partial_rows'));
    }

    public function test_preview_separates_partial_rows_from_summary(): void
    {
        $loan = $this->enableAutoPay($this->createReleasedLoan());
        $schedule = $loan->amortizationSchedules->first();

        $schedule->update([
            'principal_paid' => 100.00,
            'interest_paid' => 0,
            'status' => 'partial',
        ]);

        $dueDate = $schedule->due_date->toDateString();

        $response = $this->getJson('/api/auto-pay/preview?'.http_build_query([
            'date_from' => $dueDate,
            'date_to' => $dueDate,
        ]))->assertOk();

        $partialRows = $response->json('data.partial_rows');
        $this->assertNotEmpty($partialRows);
        $this->assertSame($schedule->id, $partialRows[0]['schedule_id']);
        $this->assertSame($loan->id, $partialRows[0]['loan_id']);
        $this->assertArrayHasKey('remaining_balance', $partialRows[0]);
        $this->assertArrayHasKey('principal_remaining', $partialRows[0]);
        $this->assertArrayHasKey('interest_remaining', $partialRows[0]);
    }

    public function test_process_creates_repayment_with_auto_pay_method(): void
    {
        $loan = $this->enableAutoPay($this->createReleasedLoan());
        $dueDate = $loan->amortizationSchedules->first()->due_date->toDateString();

        $response = $this->postJson('/api/auto-pay/process', [
            'date_from' => $dueDate,
            'date_to' => $dueDate,
        ])->assertCreated();

        $this->assertSame(1, $response->json('data.processed'));
        $this->assertSame(0, $response->json('data.failed'));
        $this->assertCount(1, $response->json('data.repayments'));
        $this->assertSame($loan->id, $response->json('data.repayments.0.loan_id'));

        $this->assertDatabaseHas('repayments', [
            'loan_id' => $loan->id,
            'method' => 'auto_pay',
            'status' => 'posted',
        ]);
    }

    public function test_process_skips_partial_schedules_unless_explicitly_included(): void
    {
        $loan = $this->enableAutoPay($this->createReleasedLoan());
        $schedule = $loan->amortizationSchedules->first();

        $schedule->update([
            'principal_paid' => 100.00,
            'status' => 'partial',
        ]);

        $dueDate = $schedule->due_date->toDateString();

        $response = $this->postJson('/api/auto-pay/process', [
            'date_from' => $dueDate,
            'date_to' => $dueDate,
        ])->assertCreated();

        $this->assertSame(0, $response->json('data.processed'));
        $this->assertSame(1, $response->json('data.skipped'));
    }

    public function test_process_includes_partial_schedule_when_id_passed(): void
    {
        $loan = $this->enableAutoPay($this->createReleasedLoan());
        $schedule = $loan->amortizationSchedules->first();

        $schedule->update([
            'principal_paid' => 100.00,
            'status' => 'partial',
        ]);

        $dueDate = $schedule->due_date->toDateString();

        $response = $this->postJson('/api/auto-pay/process', [
            'date_from' => $dueDate,
            'date_to' => $dueDate,
            'include_schedule_ids' => [$schedule->id],
        ])->assertCreated();

        $this->assertSame(1, $response->json('data.processed'));
        $this->assertDatabaseHas('repayments', [
            'loan_id' => $loan->id,
            'method' => 'auto_pay',
        ]);
    }

    public function test_preview_validates_date_range(): void
    {
        $this->getJson('/api/auto-pay/preview?'.http_build_query([
            'date_from' => '2026-04-30',
            'date_to' => '2026-04-01',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['date_to']);
    }

    public function test_preview_forbidden_without_view_permission(): void
    {
        $collector = User::factory()->create();
        $collector->assignRole(Role::where('name', 'collector')->first());
        $this->actingAs($collector);

        $this->getJson('/api/auto-pay/preview?'.http_build_query([
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-30',
        ]))->assertForbidden();
    }

    public function test_process_forbidden_without_process_permission(): void
    {
        $loanOfficer = User::factory()->create();
        $loanOfficer->assignRole(Role::where('name', 'loan_officer')->first());
        $this->actingAs($loanOfficer);

        $this->postJson('/api/auto-pay/process', [
            'date_from' => '2026-04-01',
            'date_to' => '2026-04-30',
        ])->assertForbidden();
    }
}
