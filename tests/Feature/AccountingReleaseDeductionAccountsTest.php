<?php

namespace Tests\Feature;

use App\Models\AccountingAccountMapping;
use App\Models\AccountingJournal;
use App\Models\Borrower;
use App\Models\Fee;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Services\LoanService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Each deduction withheld at release is credited to the account the
 * cooperative's accountant confirmed for its type on 2026-10-03, through a real
 * release on the default chart, and every release journal balances.
 *
 *   Processing Fee     -> 4030 Loan Processing Fee Income (unchanged)
 *   Service Fee        -> 4040 Service Fee Income
 *   Notarial Fee       -> 2030 Notarial Fees Payable
 *   Insurance Premium  -> 2040 Insurance Premium Payable
 *   Share Capital      -> 3060 Share Capital
 *   catalog fees       -> 4080 Other Fee Income
 */
class AccountingReleaseDeductionAccountsTest extends TestCase
{
    use PostsJournals, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    /**
     * One release per deduction type: the item, and the account it must land on.
     *
     * @return array<string, array{string, string}>
     */
    public static function deductionTypes(): array
    {
        return [
            'processing fee' => ['processing_fee', '4030'],
            'service fee' => ['service_fee', '4040'],
            'notarial fee' => ['notarial_fee', '2030'],
            'insurance premium' => ['insurance', '2040'],
            'share capital' => ['share_capital', '3060'],
            'catalog fee' => ['catalog_fee', '4080'],
        ];
    }

    #[DataProvider('deductionTypes')]
    public function test_a_release_credits_each_deduction_type_to_its_account_and_balances(string $kind, string $code): void
    {
        $loan = $this->releaseWith([$kind]);
        $journal = $this->releaseJournal($loan);

        $this->assertSame(1_234_56, $this->lineOn($journal, $code, 'credit'), "The {$kind} did not land on {$code}.");
        $this->assertSame(5_000_000, $this->lineOn($journal, '1110', 'debit'));
        $this->assertSame((int) round((float) $loan->net_proceeds * 100), $this->lineOn($journal, '1010', 'credit'));
        $this->assertBalanced($journal);

        // Nothing else was withheld, so nothing else is credited to income.
        if ($code !== '4030') {
            $this->assertSame(0, $this->lineOn($journal, '4030', 'credit'));
        }
    }

    public function test_a_release_with_every_deduction_type_books_each_to_its_own_account(): void
    {
        $loan = $this->releaseWith(['processing_fee', 'service_fee', 'notarial_fee', 'insurance', 'share_capital', 'catalog_fee']);
        $journal = $this->releaseJournal($loan);

        foreach (['4030', '4040', '2030', '2040', '3060', '4080'] as $code) {
            $this->assertSame(1_234_56, $this->lineOn($journal, $code, 'credit'), "Nothing landed on {$code}.");
        }

        $this->assertSame(5_000_000 - 6 * 1_234_56, $this->lineOn($journal, '1010', 'credit'));
        $this->assertBalanced($journal);
    }

    public function test_a_deduction_with_no_account_of_its_own_and_any_unitemised_remainder_stay_on_processing_fee_income(): void
    {
        $loan = $this->draftLoan([
            ['name' => 'Documentary Stamp', 'amount' => 1234.56, 'type' => 'fixed'],
        ]);
        // A total above its items: the part no item explains.
        $loan->update(['total_deductions' => 1334.56, 'net_proceeds' => 48665.44]);
        $this->approveAndRelease($loan);

        $journal = $this->releaseJournal($loan->fresh());

        $this->assertSame(1_334_56, $this->lineOn($journal, '4030', 'credit'));
        $this->assertBalanced($journal);
    }

    public function test_a_catalog_fee_named_like_a_product_fee_goes_to_that_fee_s_account(): void
    {
        Fee::create(['name' => 'Service Fee', 'type' => 'fixed', 'value' => 1234.56, 'applicable_product_ids' => null, 'conditions' => null]);

        $loan = $this->draftLoan([]);
        $this->approveAndRelease($loan);

        $journal = $this->releaseJournal($loan->fresh());

        $this->assertSame(1_234_56, $this->lineOn($journal, '4040', 'credit'));
        $this->assertSame(0, $this->lineOn($journal, '4080', 'credit'));
        $this->assertBalanced($journal);
    }

    /**
     * A typed deduction now has an account of its own, so one whose amount
     * cannot be read refuses the release rather than book its share as
     * something else, and the release rolls back whole.
     */
    public function test_a_typed_deduction_with_no_usable_amount_refuses_the_release(): void
    {
        $loan = $this->draftLoan([['name' => 'Notarial Fee', 'amount' => 500, 'type' => 'fixed']]);
        $service = app(LoanService::class);
        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');
        $loan->forceFill(['deductions' => [['name' => 'Notarial Fee', 'type' => 'fixed']]])->saveQuietly();

        $this->patchJson("/api/loans/{$loan->id}/release")
            ->assertStatus(422)
            ->assertJsonPath('errors.accounting.0', fn (string $message): bool => str_contains($message, '"Notarial Fee" has no usable amount'));

        $this->assertSame('approved', $loan->fresh()->status);
    }

    /**
     * A chart where the role was never set (the backfill skipped it) refuses a
     * release withholding that deduction, naming the role and the screen that
     * sets it, rather than book it somewhere nobody chose.
     */
    public function test_a_release_withholding_a_deduction_whose_role_is_unmapped_is_refused(): void
    {
        AccountingAccountMapping::query()->where('role', 'notarial_fees_payable')->delete();

        $loan = $this->draftLoan([['name' => 'Notarial Fee', 'amount' => 500, 'type' => 'fixed']]);
        $service = app(LoanService::class);
        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');

        $this->patchJson("/api/loans/{$loan->id}/release")
            ->assertStatus(422)
            ->assertJsonPath('errors.accounting.0', fn (string $message): bool => str_contains($message, '"notarial_fees_payable"')
                && str_contains($message, 'Default Accounts'));

        $this->assertSame('approved', $loan->fresh()->status);
    }

    /**
     * Release a ₱50,000 loan withholding ₱1,234.56 of each kind of deduction.
     *
     * @param  list<string>  $kinds
     */
    private function releaseWith(array $kinds): Loan
    {
        $items = [];
        $names = [
            'processing_fee' => 'Processing Fee',
            'service_fee' => 'Service Fee',
            'notarial_fee' => 'Notarial Fee',
            'share_capital' => 'Share Capital',
        ];

        foreach ($kinds as $kind) {
            if (isset($names[$kind])) {
                $items[] = ['name' => $names[$kind], 'amount' => 1234.56, 'type' => 'fixed'];
            }
        }

        if (in_array('catalog_fee', $kinds, true)) {
            Fee::create(['name' => 'Credit Investigation Fee', 'type' => 'fixed', 'value' => 1234.56, 'applicable_product_ids' => null, 'conditions' => null]);
        }

        $loan = $this->draftLoan($items);

        $insurance = in_array('insurance', $kinds, true)
            ? ['insurance_premium_percentage' => 2.47, 'insurance_premium_amount' => 1234.56, 'insurance_payment_type' => 'full']
            : [];

        $this->approveAndRelease($loan, $insurance);

        return $loan->fresh();
    }

    /**
     * @param  list<array{name: string, amount: float, type: string}>  $deductions
     */
    private function draftLoan(array $deductions): Loan
    {
        $product = LoanProduct::factory()->create([
            'interest_rate' => 3.0,
            'interest_method' => 'straight',
            'term' => 6,
            'frequency' => 'monthly',
            'processing_fee' => 0,
            'service_fee' => 0,
            'notarial_fee' => 0,
        ]);

        return app(LoanService::class)->createLoan([
            'borrower_id' => Borrower::factory()->create(['branch_id' => $this->branch->id])->id,
            'loan_product_id' => $product->id,
            'principal_amount' => 50000,
            'start_date' => now()->toDateString(),
            'deductions' => $deductions,
        ], $this->admin);
    }

    /**
     * @param  array<string, mixed>  $insurance
     */
    private function approveAndRelease(Loan $loan, array $insurance = []): void
    {
        $service = app(LoanService::class);
        $service->submitForReview($loan);
        $service->approve($loan, $this->admin, 'Approved for testing');

        $this->patchJson("/api/loans/{$loan->id}/release", $insurance)->assertOk();
    }

    private function releaseJournal(Loan $loan): AccountingJournal
    {
        return AccountingJournal::query()
            ->where('postable_type', $loan->getMorphClass())
            ->where('postable_id', $loan->getKey())
            ->where('source', 'loan_release')
            ->firstOrFail();
    }

    private function lineOn(AccountingJournal $journal, string $code, string $side): int
    {
        return (int) $journal->lines()->where('accounting_account_id', $this->account($code))->sum($side);
    }

    private function assertBalanced(AccountingJournal $journal): void
    {
        $debits = (int) $journal->lines()->sum('debit');
        $credits = (int) $journal->lines()->sum('credit');

        $this->assertGreaterThan(0, $debits);
        $this->assertSame($debits, $credits, 'The release journal does not balance.');
        $this->assertSame((int) $journal->total_debit, (int) $journal->total_credit);
    }
}
