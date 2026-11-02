<?php

namespace Tests\Feature\InsuranceOwed;

use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\InsuranceOwed\Concerns\OwesInsurancePremium;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A restructure's release moves the source's premium owed to the new loan:
 * audited on both loans, added on top of the new loan's own premium owed,
 * and disclosed on its own line. Terms approved while the premium still
 * counted as restructurable balance are refused until re-approved, so the
 * premium is never charged twice or written off while carried.
 *
 * Source: ₱70,800 owed on its periods plus ₱400 premium owed.
 */
class InsuranceOwedRestructureTest extends TestCase
{
    use OwesInsurancePremium, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /** A restructure of `$source` for ₱70,800, submitted and approved. */
    private function approvedRestructure(Loan $source): Loan
    {
        $id = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => self::SCHEDULE_TOTAL,
            'start_date' => now()->toDateString(),
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/loans/{$id}/submit")->assertOk();
        $approver = User::factory()->create();
        $approver->assignRole(Role::where('name', 'admin')->first());
        $this->actingAs($approver);
        $this->patchJson("/api/loans/{$id}/approve", ['approval_remarks' => 'ok'])->assertOk();
        $this->actingAs($this->admin);

        return Loan::findOrFail($id);
    }

    public function test_terms_approved_with_the_premium_inside_the_principal_are_refused_until_re_approved(): void
    {
        $source = $this->loanOwingPremium();
        $new = $this->approvedRestructure($source);

        // Old-style saved figures: the premium counted in the outstanding and
        // the principal (scenario A: charged twice once carried).
        DB::table('loans')->where('id', $new->id)->update([
            'principal_amount' => self::SCHEDULE_TOTAL + self::OWED,
            'restructure_principal' => self::SCHEDULE_TOTAL + self::OWED,
            'restructure_outstanding' => self::SCHEDULE_TOTAL + self::OWED,
            'restructure_shortfall' => 0,
        ]);

        $this->patchJson("/api/loans/{$new->id}/release")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['principal_amount' => 'The terms changed since approval; re-approve the restructure.']);

        $this->assertSame('approved', $new->fresh()->status);
        $this->assertSame('released', $source->fresh()->status);
        $this->assertEqualsWithDelta(self::OWED, $this->owed($source), 0.001);
    }

    public function test_terms_approved_with_the_premium_in_the_shortfall_are_refused_until_re_approved(): void
    {
        $source = $this->loanOwingPremium();
        $new = $this->approvedRestructure($source);

        // Old-style saved figures: ₱400 written off that would also be carried
        // (scenario B).
        DB::table('loans')->where('id', $new->id)->update([
            'restructure_outstanding' => self::SCHEDULE_TOTAL + self::OWED,
            'restructure_shortfall' => self::OWED,
        ]);

        $this->patchJson("/api/loans/{$new->id}/release")
            ->assertStatus(422)
            ->assertJsonValidationErrors('principal_amount');

        $this->assertSame('approved', $new->fresh()->status);
        $this->assertEqualsWithDelta(0.00, (float) $source->fresh()->write_off_amount, 0.001);
    }

    public function test_the_carried_premium_is_audited_on_both_loans(): void
    {
        $source = $this->loanOwingPremium();
        $new = $this->approvedRestructure($source);

        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();

        $carried = AuditLog::where('action', 'insurance_premium_carried_over')
            ->where('auditable_type', $new->getMorphClass())
            ->where('auditable_id', $new->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(0.00, (float) $carried->old_values['insurance_remaining_balance'], 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) $carried->new_values['insurance_remaining_balance'], 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) $carried->new_values['carried_premium'], 0.001);
        $this->assertSame($source->id, $carried->new_values['carried_from_loan_id']);

        $closed = AuditLog::where('action', 'restructure_closed')
            ->where('auditable_id', $source->id)
            ->firstOrFail();
        $this->assertEqualsWithDelta(self::OWED, (float) $closed->old_values['insurance_remaining_balance'], 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) $closed->new_values['insurance_premium_carried_over'], 0.001);
        $this->assertEqualsWithDelta(self::SCHEDULE_TOTAL, (float) $closed->new_values['restructured_balance'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $closed->new_values['write_off_amount'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $closed->new_values['balance_drift_since_approval'], 0.001);

        $source->refresh();
        $this->assertEqualsWithDelta(0.00, (float) $source->insurance_remaining_balance, 0.001);
        $this->assertEqualsWithDelta(self::SCHEDULE_TOTAL, (float) $source->restructured_balance, 0.001);
    }

    public function test_the_carried_premium_is_added_to_the_new_loan_s_own_premium_owed_and_disclosed_on_its_own_line(): void
    {
        $source = $this->loanOwingPremium();
        $new = $this->approvedRestructure($source);

        // The new loan's own premium: 1% of ₱70,800 = ₱708, ₱200 collected, ₱508 owed.
        $this->patchJson("/api/loans/{$new->id}/release", [
            'insurance_premium_percentage' => 1.0,
            'insurance_payment_type' => 'partial',
            'insurance_partial_amount' => 200,
        ])->assertOk();

        $new->refresh();
        $this->assertEqualsWithDelta(708.00, (float) $new->insurance_premium_amount, 0.001);
        $this->assertEqualsWithDelta(508.00 + self::OWED, (float) $new->insurance_remaining_balance, 0.001);
        $this->assertEqualsWithDelta(self::OWED, (float) $new->insurance_carried_over, 0.001);

        $items = collect($this->getJson("/api/loans/{$new->id}/disclosure")->assertOk()->json('data.non_finance_charges.items'));

        $own = $items->firstWhere('name', 'Insurance Premium');
        $this->assertEquals(['name' => 'Insurance Premium', 'amount' => 708, 'deducted' => 200, 'owed' => 508], $own);

        $carriedLine = $items->firstWhere('name', 'Insurance premium carried from the previous loan');
        $this->assertEquals(
            ['name' => 'Insurance premium carried from the previous loan', 'amount' => 400, 'deducted' => 0, 'owed' => 400, 'provisional' => false],
            $carriedLine,
        );
    }

    public function test_the_disclosure_signed_before_release_shows_the_premium_to_be_carried_as_provisional(): void
    {
        $source = $this->loanOwingPremium();
        $new = $this->approvedRestructure($source);
        $this->assertSame('approved', $new->fresh()->status);

        $line = collect($this->getJson("/api/loans/{$new->id}/disclosure")->assertOk()->json('data.non_finance_charges.items'))
            ->firstWhere('name', 'Insurance premium carried from the previous loan');

        $this->assertEquals(
            ['name' => 'Insurance premium carried from the previous loan', 'amount' => 400, 'deducted' => 0, 'owed' => 400, 'provisional' => true],
            $line,
        );

        // A payment on the source before release lowers it: the line follows.
        $this->pay($source, 150);

        $line = collect($this->getJson("/api/loans/{$new->id}/disclosure")->assertOk()->json('data.non_finance_charges.items'))
            ->firstWhere('name', 'Insurance premium carried from the previous loan');
        $this->assertEquals(250, $line['amount']);
        $this->assertTrue($line['provisional']);
    }

    public function test_legacy_style_terms_on_a_source_owing_no_premium_still_release(): void
    {
        $source = $this->loanOwingPremium();
        // The premium is paid off before the restructure; periods untouched.
        $this->pay($source, self::OWED);
        $this->assertEqualsWithDelta(0.00, $this->owed($source), 0.001);

        $new = $this->approvedRestructure($source);

        // A saved outstanding above what the periods owe, as an old
        // application could carry: with no premium owed it is not refused.
        DB::table('loans')->where('id', $new->id)->update([
            'restructure_outstanding' => self::SCHEDULE_TOTAL + self::OWED,
            'restructure_shortfall' => 0,
        ]);

        $this->patchJson("/api/loans/{$new->id}/release")->assertOk();

        $this->assertSame('restructured', $source->fresh()->status);
        $this->assertEqualsWithDelta(0.00, (float) $new->fresh()->insurance_remaining_balance, 0.001);
    }
}
