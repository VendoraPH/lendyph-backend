<?php

namespace Database\Factories;

use App\Models\SavingsAccount;
use App\Models\SavingsInterestRun;
use App\Models\SavingsInterestRunLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsInterestRunLine>
 */
class SavingsInterestRunLineFactory extends Factory
{
    protected $model = SavingsInterestRunLine::class;

    public function definition(): array
    {
        return [
            'run_id' => SavingsInterestRun::factory(),
            'account_id' => SavingsAccount::factory(),
            'days' => 92,
            'balance_days' => 92 * 1_000_000,
            'average_daily_balance' => '10000.00',
            'rate' => '1.0000',
            'rate_segments' => [['from' => '2026-07-01', 'to' => '2026-09-30', 'rate' => '1.0000', 'balance_days' => 92 * 1_000_000]],
            'gross_interest' => '25.21',
            'tax_rate' => '0.0000',
            'withholding_tax' => '0.00',
            'net_interest' => '25.21',
            'skip_reason' => null,
        ];
    }
}
