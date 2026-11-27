<?php

namespace App\Models;

use Database\Factories\SavingsInterestRunLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One account's figures in an interest run, as the checker approves them
 * (design §1, §5): days, centavo-days, average daily balance, the rate
 * segments, gross, tax and net, or the reason it earned nothing. Not
 * Auditable: the run's own audit rows cover a run's lines.
 *
 * @property int $id
 * @property int $run_id
 * @property int $account_id
 * @property int $days
 * @property int $balance_days
 * @property string $average_daily_balance
 * @property string $rate
 * @property list<array<string, mixed>>|null $rate_segments
 * @property string $gross_interest
 * @property string $tax_rate
 * @property string $withholding_tax
 * @property string $net_interest
 * @property string|null $skip_reason
 */
class SavingsInterestRunLine extends Model
{
    /** @use HasFactory<SavingsInterestRunLineFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'account_id',
        'days',
        'balance_days',
        'average_daily_balance',
        'rate',
        'rate_segments',
        'gross_interest',
        'tax_rate',
        'withholding_tax',
        'net_interest',
        'skip_reason',
        'interest_transaction_id',
        'tax_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'run_id' => 'integer',
            'account_id' => 'integer',
            'days' => 'integer',
            'balance_days' => 'integer',
            'average_daily_balance' => 'decimal:2',
            'rate' => 'decimal:4',
            'rate_segments' => 'array',
            'gross_interest' => 'decimal:2',
            'tax_rate' => 'decimal:4',
            'withholding_tax' => 'decimal:2',
            'net_interest' => 'decimal:2',
            'interest_transaction_id' => 'integer',
            'tax_transaction_id' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SavingsInterestRun::class, 'run_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class, 'account_id');
    }

    public function interestTransaction(): BelongsTo
    {
        return $this->belongsTo(SavingsTransaction::class, 'interest_transaction_id');
    }

    public function taxTransaction(): BelongsTo
    {
        return $this->belongsTo(SavingsTransaction::class, 'tax_transaction_id');
    }
}
