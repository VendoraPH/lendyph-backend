<?php

namespace Tests\Feature\Accounting\OpeningBalances;

use App\Models\AccountingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\Traits\PostsJournals;
use Tests\Traits\SetupLendyPH;

/**
 * Shared set-up for the opening balances screen's tests: the seeded chart, a
 * signed-in super admin, and the three calls the screen makes.
 */
trait OpeningBalanceTestHelpers
{
    use PostsJournals, SetupLendyPH;

    private const URL = '/api/accounting/opening-balances';

    private const AS_OF = '2026-08-31';

    protected function setUpOpeningBalances(): void
    {
        $this->seedAndLogin();
        $this->seedChartOfAccounts();
    }

    /**
     * ₱400,000.00 cash and ₱2,100,000.00 loans against ₱150,000.00 other
     * liabilities, in centavos: ₱2,350,000.00 more debit than credit.
     *
     * @return list<array{account_id: int, debit: int, credit: int}>
     */
    protected function unbalancedLines(): array
    {
        return [
            ['account_id' => $this->account('1010'), 'debit' => 40_000_000, 'credit' => 0],
            ['account_id' => $this->account('1110'), 'debit' => 210_000_000, 'credit' => 0],
            ['account_id' => $this->account('2300'), 'debit' => 0, 'credit' => 15_000_000],
        ];
    }

    /** @return list<array{account_id: int, debit: int, credit: int}> */
    protected function balancedLines(): array
    {
        return [
            ...$this->unbalancedLines(),
            ['account_id' => $this->account('3050'), 'debit' => 0, 'credit' => 235_000_000],
        ];
    }

    /** @param  list<array<string, int>>  $lines */
    protected function save(array $lines, ?int $editVersion = null, string $asOf = self::AS_OF): TestResponse
    {
        $body = ['as_of' => $asOf, 'lines' => $lines];

        if ($editVersion !== null) {
            $body['edit_version'] = $editVersion;
        }

        return $this->putJson(self::URL, $body);
    }

    protected function finalise(int $editVersion): TestResponse
    {
        return $this->postJson(self::URL.'/finalise', ['edit_version' => $editVersion]);
    }

    /** Saves a balanced draft and answers its edit_version. */
    protected function saveBalancedDraft(): int
    {
        return (int) $this->save($this->balancedLines())->assertOk()->json('data.edit_version');
    }

    protected function closeMonth(string $date): void
    {
        AccountingPeriod::query()->create(AccountingPeriod::attributesForMonth(CarbonImmutable::parse($date)) + [
            'status' => 'closed',
            'closed_by' => $this->admin->id,
            'closed_at' => now(),
        ]);
    }
}
