<?php

namespace Tests\Feature\Phase6\Amla\Events;

use App\Models\Loan;
use App\Services\Amla\Events\EventCannotBeBuilt;
use App\Services\Amla\Events\Sources\LoanReleases;
use stdClass;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Round 9: AMLA's loan release events name the loan by its display number
 * (its LN- number, else its application number; imported loans are no
 * event), and "Loan #id" only when it has none. Stored AMLA rows are never
 * changed.
 */
class AmlaLoanDisplayNumberTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_release_event_carries_its_ln_number(): void
    {
        $loan = $this->createReleasedLoan();

        $built = app(LoanReleases::class)->build($this->rowOf($loan), 'test');

        $this->assertSame($loan->fresh()->loan_account_number, $built->events[0]['reference']);
    }

    public function test_a_refusal_names_the_loan_by_its_application_number_when_it_has_no_ln_number(): void
    {
        $loan = $this->releasedWithoutProceeds();
        Loan::query()->whereKey($loan->id)->update(['loan_account_number' => null]);

        $this->assertRefusal($loan, "The release of {$loan->application_number} has no net proceeds of ₱0.00 or more.");
    }

    public function test_a_refusal_reads_loan_id_only_when_the_loan_has_no_number(): void
    {
        $loan = $this->releasedWithoutProceeds();
        Loan::query()->whereKey($loan->id)->update(['loan_account_number' => null, 'application_number' => '']);

        $this->assertRefusal($loan, "The release of Loan #{$loan->id} has no net proceeds of ₱0.00 or more.");
    }

    private function releasedWithoutProceeds(): Loan
    {
        return Loan::factory()->create([
            'branch_id' => $this->branch->id, 'status' => 'ongoing', 'released_at' => now(), 'net_proceeds' => -1,
        ]);
    }

    private function assertRefusal(Loan $loan, string $message): void
    {
        try {
            app(LoanReleases::class)->build($this->rowOf($loan), 'test');
            $this->fail('The release should not build.');
        } catch (EventCannotBeBuilt $refused) {
            $this->assertSame($message, $refused->getMessage());
        }
    }

    private function rowOf(Loan $loan): stdClass
    {
        return app(LoanReleases::class)->rows()->where('loans.id', $loan->id)->sole();
    }
}
