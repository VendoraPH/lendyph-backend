<?php

namespace Tests\Feature;

use App\Models\GCashNonMember;
use App\Models\GCashTransaction;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * `per_page` below 1 on the four lists that clamped only the top.
 *
 * Every other list validates `per_page` as `integer|min:1`. These four read it
 * straight into `min((int) per_page, 100)`: a negative value reached MySQL as an
 * `offset` with no `limit` and the request died with a 500, and 0 was quietly
 * replaced by the default page size inside paginate(). Both are now a 422, and a
 * valid size still pages.
 */
class PerPageLowerBoundTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badPerPage(): array
    {
        return [
            'negative' => ['-1'],
            'zero' => ['0'],
            'not a number' => ['abc'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function listUrls(): array
    {
        $loan = $this->createReleasedLoan();

        return [
            'gcash non-members' => '/api/gcash/non-members',
            'gcash transactions' => '/api/gcash/transactions',
            'loan adjustments' => "/api/loans/{$loan->id}/adjustments",
            'loan repayments' => "/api/loans/{$loan->id}/repayments",
        ];
    }

    #[DataProvider('badPerPage')]
    public function test_a_per_page_below_one_is_rejected_on_every_list(string $perPage): void
    {
        foreach ($this->listUrls() as $url) {
            $this->getJson("{$url}?per_page={$perPage}")
                ->assertStatus(422)
                ->assertJsonValidationErrors(['per_page']);
        }
    }

    public function test_a_valid_per_page_still_pages_and_the_top_is_still_clamped(): void
    {
        GCashNonMember::factory()->count(3)->create();
        GCashTransaction::factory()->count(3)->create();

        foreach (['/api/gcash/non-members', '/api/gcash/transactions'] as $url) {
            $this->getJson("{$url}?per_page=2")
                ->assertOk()
                ->assertJsonCount(2, 'data')
                ->assertJsonPath('meta.per_page', 2)
                ->assertJsonPath('meta.last_page', 2);

            $this->getJson("{$url}?per_page=500")
                ->assertOk()
                ->assertJsonPath('meta.per_page', 100);
        }
    }

    public function test_omitting_per_page_keeps_each_lists_default(): void
    {
        $urls = $this->listUrls();

        $this->getJson($urls['gcash non-members'])->assertOk()->assertJsonPath('meta.per_page', 25);
        $this->getJson($urls['gcash transactions'])->assertOk()->assertJsonPath('meta.per_page', 25);
        $this->getJson($urls['loan adjustments'])->assertOk()->assertJsonPath('meta.per_page', 15);
        $this->getJson($urls['loan repayments'])->assertOk()->assertJsonPath('meta.per_page', 15);
    }
}
