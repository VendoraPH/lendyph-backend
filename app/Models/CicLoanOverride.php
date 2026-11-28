<?php

namespace App\Models;

use Database\Factories\CicLoanOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A loan's own CIC classification, over its product's mapping, with the reason
 * staff gave (prompt §3.3). A null field keeps the product's value.
 *
 * @property int $id
 * @property int $loan_id
 * @property string|null $record_type CI|CN
 * @property string|null $contract_type
 * @property string|null $transaction_type
 * @property string $reason
 * @property int $created_by
 * @property int $updated_by
 */
class CicLoanOverride extends Model
{
    /** @use HasFactory<CicLoanOverrideFactory> */
    use HasFactory;

    protected $fillable = ['loan_id', 'record_type', 'contract_type', 'transaction_type', 'reason'];

    protected function casts(): array
    {
        return [
            'loan_id' => 'integer',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
