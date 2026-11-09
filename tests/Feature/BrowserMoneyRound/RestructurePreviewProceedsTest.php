<?php

namespace Tests\Feature\BrowserMoneyRound;

use App\Models\Loan;
use App\Models\User;
use Tests\Feature\Ledger\Release\ReleasesLoans;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Follow-up round 3, lead's call 3. The restructure page's preview
 * (POST /loans/preview with `source_loan_id`) states what the restructure's
 * release does with its deductions, by the code the release preview states it
 * with: `collected_now` (every deduction, collected from the member) and
 * `paid_out` (nothing), as 2dp strings. Before, only GET
 * /loans/{id}/release-preview had them, so the create page read the total
 * deductions as collected and ₱0.00 as paid out on its own.
 *
 * The restructure edit form sends `loan_id` and `source_loan_id` together, so
 * one preview carries both the edit's figures and the restructure's. Each id
 * keeps its own checks, and the edited loan must restructure that source.
 *
 * createReleasedLoan(): ₱60,000.00 over 6 monthly periods at 3% straight,
 * ₱70,800.00 of principal and interest open.
 */
class RestructurePreviewProceedsTest extends TestCase
{
    use PostsJournals, ReleasesLoans, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function previewBody(Loan $source, float $principal, array $extra = []): array
    {
        return [
            'source_loan_id' => $source->id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => $principal,
            'interest_rate' => 3.0,
            'term' => 6,
            'frequency' => 'monthly',
            'start_date' => now()->toDateString(),
            ...$extra,
        ];
    }

    private function draftRestructureOf(Loan $source, float $principal): Loan
    {
        $response = $this->postJson("/api/loans/{$source->id}/restructure", [
            'borrower_id' => $source->borrower_id,
            'loan_product_id' => $source->loan_product_id,
            'principal_amount' => $principal,
            'start_date' => now()->toDateString(),
        ])->assertCreated();

        return Loan::findOrFail($response->json('data.id'));
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_the_restructure_preview_collects_every_deduction_and_pays_nothing_out(): void
    {
        $source = $this->createReleasedLoan();

        $data = $this->postJson('/api/loans/preview', $this->previewBody($source, 50000, [
            'deductions' => [
                ['name' => 'Processing Fee', 'amount' => 2, 'type' => 'percentage'],
                ['name' => 'Notarial Fee', 'amount' => 150.5, 'type' => 'fixed'],
            ],
        ]))->assertOk()->json('data');

        $this->assertIsArray($data['restructure'] ?? null, 'data.restructure is missing');
        $this->assertEqualsWithDelta(1150.50, (float) $data['deductions']['total_deductions'], 0.001, 'setup: ₱1,000.00 + ₱150.50');
        $this->assertSame(sprintf('%.2f', $data['deductions']['total_deductions']), $data['restructure']['collected_now'] ?? null);
        $this->assertSame('0.00', $data['restructure']['paid_out'] ?? null);
        // The restructure figures are unchanged.
        $this->assertEqualsWithDelta(70800.00, (float) $data['restructure']['restructurable_balance'], 0.001);
        $this->assertEqualsWithDelta(20800.00, (float) $data['restructure']['shortfall'], 0.001);
    }

    public function test_the_form_preview_and_the_release_preview_agree_on_what_is_collected(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->approvedRestructureOf($source, 70800);

        $release = $this->getJson("/api/loans/{$new->id}/release-preview")->assertOk()->json('data.restructure');
        $form = $this->postJson('/api/loans/preview', $this->previewBody($source, 70800))->assertOk()->json('data.restructure');

        $this->assertNotSame('0.00', $release['collected_now'], 'setup: the product charges fees');
        $this->assertSame($release['collected_now'], $form['collected_now'] ?? null);
        $this->assertSame($release['paid_out'], $form['paid_out'] ?? null);
    }

    public function test_with_no_deductions_known_yet_nothing_is_stated_as_collected(): void
    {
        $source = $this->createReleasedLoan();

        $restructure = $this->postJson('/api/loans/preview', [
            'source_loan_id' => $source->id,
            'principal_amount' => 50000,
        ])->assertOk()->json('data.restructure');

        $this->assertArrayHasKey('collected_now', $restructure);
        $this->assertNull($restructure['collected_now']);
        $this->assertSame('0.00', $restructure['paid_out']);
    }

    public function test_an_ordinary_preview_has_no_restructure_block(): void
    {
        $source = $this->createReleasedLoan();
        $body = $this->previewBody($source, 50000);
        unset($body['source_loan_id']);

        $this->assertArrayNotHasKey('restructure', $this->postJson('/api/loans/preview', $body)->assertOk()->json('data'));
    }

    public function test_the_restructure_edit_form_previews_with_both_ids_in_one_call(): void
    {
        $source = $this->createReleasedLoan();
        $draft = $this->draftRestructureOf($source, 70800);

        $data = $this->postJson('/api/loans/preview', $this->previewBody($source, 60000, [
            'loan_id' => $draft->id,
            'deductions' => [['name' => 'Processing Fee', 'amount' => 1, 'type' => 'percentage']],
        ]))->assertOk()->json('data');

        $this->assertIsArray($data['restructure'] ?? null, 'data.restructure is missing');
        $this->assertEqualsWithDelta(70800.00, (float) $data['restructure']['restructurable_balance'], 0.001);
        $this->assertEqualsWithDelta(10800.00, (float) $data['restructure']['shortfall'], 0.001);
        $this->assertTrue($data['restructure']['remarks_required']);
        $this->assertSame('600.00', $data['restructure']['collected_now']);
        $this->assertSame('0.00', $data['restructure']['paid_out']);
        $this->assertNotNull($data['amortization']);
    }

    public function test_the_edited_loan_must_restructure_the_named_source(): void
    {
        $source = $this->createReleasedLoan();
        $other = $this->createReleasedLoan();
        $draft = $this->draftRestructureOf($source, 70800);

        $this->postJson('/api/loans/preview', $this->previewBody($other, 60000, ['loan_id' => $draft->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['loan_id' => 'The loan being edited is not a restructure of this loan.'])
            ->assertJsonMissingPath('data');

        // An ordinary draft restructures nothing.
        $ordinary = $this->approvedLoan();
        $this->postJson('/api/loans/preview', $this->previewBody($source, 60000, ['loan_id' => $ordinary->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['loan_id']);
    }

    public function test_both_ids_need_both_permissions(): void
    {
        $source = $this->createReleasedLoan();
        $draft = $this->draftRestructureOf($source, 70800);
        $body = $this->previewBody($source, 60000, ['loan_id' => $draft->id]);

        $this->actingAs($this->userWith('loans:update'));
        $this->postJson('/api/loans/preview', $body)->assertForbidden();

        $this->actingAs($this->userWith('loans:restructure'));
        $this->postJson('/api/loans/preview', $body)->assertForbidden();

        $this->actingAs($this->userWith('loans:update', 'loans:restructure'));
        $this->postJson('/api/loans/preview', $body)->assertOk()->assertJsonPath('data.restructure.paid_out', '0.00');
    }

    public function test_the_edited_loan_keeps_its_own_editable_check(): void
    {
        $source = $this->createReleasedLoan();
        $new = $this->approvedRestructureOf($source, 70800);

        $this->postJson('/api/loans/preview', $this->previewBody($source, 60000, ['loan_id' => $new->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }
}
