<?php

namespace Tests\Feature;

use App\Models\Borrower;
use App\Models\GCashTransaction;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /gcash/transactions validates its filters, like the other lists. They
 * were read raw and gated on truthiness, so `?borrower_id=0` dropped the
 * member filter and answered with every member's rows (and `meta.totals` for
 * all of them), and a date that wasn't a date reached the query as text. A
 * bad filter is now a 422 in words; a good one narrows the list and its
 * totals together.
 */
class GCashTransactionListFiltersTest extends TestCase
{
    use SetupLendyPH;

    private Borrower $member;

    private Borrower $otherMember;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        $this->otherMember = Borrower::factory()->create(['branch_id' => $this->branch->id]);
    }

    public function test_the_member_filter_takes_only_a_whole_number_from_1(): void
    {
        $mine = $this->row($this->member, 'cash_in', 'paid', '1000.00');
        $this->row($this->otherMember, 'cash_in', 'paid', '2000.00');

        foreach (['0', '-3', '1.5', 'abc'] as $bad) {
            $this->list(['borrower_id' => $bad])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['borrower_id' => 'Choose the member from the list.']);
        }

        $response = $this->list(['borrower_id' => $this->member->id])->assertOk();
        $this->assertSame([$mine->id], array_column($response->json('data'), 'id'));
        $this->assertSame(1, $response->json('meta.totals.count'));
        $this->assertSame('1000.00', $response->json('meta.totals.amount'));
    }

    public function test_a_date_that_is_not_a_real_yyyy_mm_dd_date_is_refused(): void
    {
        foreach (['yesterday', '2026/11/30', '30-11-2026', '20261130', '2026-13-01', '2026-02-30'] as $bad) {
            $this->list(['start_date' => $bad])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['start_date' => 'The From date must be a real date, written as YYYY-MM-DD.']);
            $this->list(['end_date' => $bad])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['end_date' => 'The To date must be a real date, written as YYYY-MM-DD.']);
        }
    }

    public function test_the_to_date_can_not_be_before_the_from_date(): void
    {
        $this->row($this->member, 'cash_in', 'paid', '1000.00', '2026-11-30 09:00:00');

        $this->list(['start_date' => '2026-11-30', 'end_date' => '2026-11-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['end_date' => 'The To date can\'t be before the From date.']);

        $this->list(['start_date' => '2026-11-30', 'end_date' => '2026-11-30'])->assertOk()->assertJsonPath('meta.totals.count', 1);
        $this->list(['start_date' => '2026-11-30'])->assertOk()->assertJsonPath('meta.totals.count', 1);
        $this->list(['end_date' => '2026-11-30'])->assertOk()->assertJsonPath('meta.totals.count', 1);
    }

    public function test_the_type_and_status_take_only_their_listed_values(): void
    {
        $cashIn = $this->row($this->member, 'cash_in', 'paid', '1000.00');
        $cashOut = $this->row($this->member, 'cash_out', 'completed', '2000.00');

        foreach (['all', 'cash', 'CASH_IN', 'cash in'] as $bad) {
            $this->list(['type' => $bad])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['type' => 'Choose Cash In or Cash Out as the type.']);
        }
        foreach (['all', 'pending_only', 'voided', 'PAID'] as $bad) {
            $this->list(['status' => $bad])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['status' => 'Choose Pending, Paid or Completed as the status.']);
        }

        $this->assertSame([$cashOut->id], array_column($this->list(['type' => 'cash_out'])->assertOk()->json('data'), 'id'));
        $this->assertSame([$cashIn->id], array_column($this->list(['status' => 'paid'])->assertOk()->json('data'), 'id'));
    }

    public function test_rows_per_page_below_1_is_refused_in_words(): void
    {
        foreach (['0', '-1', 'ten'] as $bad) {
            $this->list(['per_page' => $bad])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['per_page' => 'Rows per page must be a whole number from 1.']);
        }
    }

    /**
     * A guard, not a new rule: a caller without gcash:view is refused before
     * any filter is read, so bad filters never tell them more than a 403.
     */
    public function test_a_caller_without_gcash_view_is_refused_before_the_filters_are_read(): void
    {
        $this->actingAs(User::factory()->inBranches($this->branch)->create());

        $this->list(['borrower_id' => '0', 'type' => 'bogus', 'start_date' => 'yesterday'])->assertForbidden();
    }

    /**
     * @param  array<string, string|int>  $filters
     */
    private function list(array $filters): TestResponse
    {
        return $this->getJson('/api/gcash/transactions?'.http_build_query($filters));
    }

    private function row(Borrower $member, string $type, string $status, string $amount, ?string $date = null): GCashTransaction
    {
        return GCashTransaction::factory()->create([
            'type' => $type,
            'status' => $status,
            'amount' => $amount,
            'charge_amount' => '20.00',
            'total_amount' => number_format((float) $amount + ($type === 'cash_in' ? 20 : -20), 2, '.', ''),
            'transaction_date' => $date ?? now(),
            'borrower_id' => $member->id,
            'transactor_user_id' => $this->admin->id,
        ]);
    }
}
