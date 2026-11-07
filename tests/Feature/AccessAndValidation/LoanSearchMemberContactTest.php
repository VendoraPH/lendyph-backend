<?php

namespace Tests\Feature\AccessAndValidation;

use App\Models\Loan;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * GET /loans search matches a member's contact number and email only for a
 * caller holding `borrowers:view`. Without it, a hit would tell the caller that
 * a number or an address belongs to a member, even though the row hides both.
 * Name, member code, account number and product stay searchable for everyone
 * who can read loans.
 */
class LoanSearchMemberContactTest extends TestCase
{
    use SetupLendyPH;

    private const CONTACT = '09171234567';

    private const EMAIL = 'rosalinda.private@example.test';

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->loan = $this->createReleasedLoan([
            'product' => ['name' => 'Harvest Advance'],
        ]);
        $this->loan->borrower->update([
            'first_name' => 'Rosalinda',
            'last_name' => 'Macaraeg',
            'contact_number' => self::CONTACT,
            'email' => self::EMAIL,
        ]);
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->inBranches($this->branch)->create();
        $user->assignRole($role);
        $this->actingAs($user);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function contactTerms(): array
    {
        return [
            'whole contact number' => [self::CONTACT],
            'part of a contact number' => ['0917123'],
            'whole email' => [self::EMAIL],
            'part of an email' => ['rosalinda.private'],
        ];
    }

    #[DataProvider('contactTerms')]
    public function test_a_caller_without_borrowers_view_cannot_test_a_contact_detail(string $term): void
    {
        foreach (['manager', 'bod1'] as $role) {
            $this->actingAsRole($role);

            foreach (['', '&view=summary'] as $view) {
                $response = $this->getJson('/api/loans?search='.urlencode($term).$view)->assertOk();

                $this->assertSame([], $response->json('data'), "{$role}{$view}");
                $this->assertSame(0, $response->json('meta.total'), "{$role}{$view}");
                $this->assertEquals(0, $response->json('meta.totals.total_outstanding'), "{$role}{$view}");
            }
        }
    }

    #[DataProvider('contactTerms')]
    public function test_a_caller_with_borrowers_view_still_finds_the_loan_by_it(string $term): void
    {
        $this->actingAsRole('cashier');

        $this->assertSame([$this->loan->id], $this->idsFor($term));
    }

    public function test_a_caller_without_borrowers_view_still_searches_everything_else(): void
    {
        $this->actingAsRole('manager');

        $terms = [
            'Rosalinda',
            'Macaraeg',
            'Rosalinda Macaraeg',
            $this->loan->borrower->borrower_code,
            $this->loan->loan_account_number,
            $this->loan->application_number,
            'Harvest Advance',
        ];

        foreach ($terms as $term) {
            $this->assertSame([$this->loan->id], $this->idsFor($term), "search for [{$term}]");
        }
    }

    /**
     * @return list<int>
     */
    private function idsFor(string $term): array
    {
        return array_column($this->getJson('/api/loans?search='.urlencode($term))->assertOk()->json('data'), 'id');
    }
}
