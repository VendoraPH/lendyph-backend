<?php

namespace Tests\Feature;

use App\Models\Loan;
use App\Models\ShareCapitalPledge;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The release, extension "pay", Record Payment and share capital requests
 * refuse a missing or overlong `reference_number` in words, never with
 * Laravel's default "The reference number field is required unless method is
 * in cash."
 */
class ReferenceNumberMessagesTest extends TestCase
{
    use SetupLendyPH;

    private const REQUIRED = 'Enter the reference number. Only a cash payment can be saved without one.';

    private const TOO_LONG = 'The reference number can be at most 100 characters.';

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->loan = $this->createReleasedLoan();
    }

    /**
     * Each request's method and URL, with the body it needs past its own
     * reference number.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function requests(): array
    {
        $pledge = ShareCapitalPledge::factory()->create(['borrower_id' => $this->loan->borrower_id]);

        return [
            'release' => ['PATCH', "/api/loans/{$this->loan->id}/release", []],
            'extension pay' => ['POST', "/api/loans/{$this->loan->id}/extend", ['interest_option' => 'pay']],
            'record payment' => ['POST', "/api/loans/{$this->loan->id}/repayments", ['payment_date' => now()->toDateString(), 'amount_paid' => 100]],
            'share capital ledger' => ['POST', '/api/share-capital/ledger', []],
            'pledge entry' => ['POST', "/api/pledges/{$pledge->id}/entries", []],
            'bulk pledge entries' => ['POST', '/api/pledges/bulk-entries', []],
        ];
    }

    public function test_a_missing_reference_on_a_non_cash_method_is_refused_in_words(): void
    {
        foreach ($this->requests() as $name => [$method, $url, $body]) {
            $response = $this->json($method, $url, $body + ['method' => 'gcash'])->assertStatus(422);

            $this->assertSame(self::REQUIRED, $response->json('errors.reference_number.0'), $name);
        }
    }

    public function test_an_overlong_reference_is_refused_in_words(): void
    {
        $requests = $this->requests() + ['auto-credit' => ['POST', '/api/auto-credit/process', []]];

        foreach ($requests as $name => [$method, $url, $body]) {
            $response = $this->json($method, $url, $body + ['method' => 'cash', 'reference_number' => str_repeat('R', 101)])->assertStatus(422);

            $this->assertSame(self::TOO_LONG, $response->json('errors.reference_number.0'), $name);
        }
    }
}
