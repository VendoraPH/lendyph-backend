<?php

namespace App\Models;

use Database\Factories\CicProductMappingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A loan product's default CIC classification (prompt §3.3): CI or CN, the
 * contract type, transaction type and purpose codes. A loan's own structure
 * decides first, this next, and the loan's override (CicLoanOverride) last.
 *
 * @property int $id
 * @property int $loan_product_id
 * @property string $record_type CI|CN
 * @property string $contract_type
 * @property string $transaction_type
 * @property string|null $purpose_code
 * @property int $updated_by
 */
class CicProductMapping extends Model
{
    /** @use HasFactory<CicProductMappingFactory> */
    use HasFactory;

    protected $fillable = ['loan_product_id', 'record_type', 'contract_type', 'transaction_type', 'purpose_code'];

    protected function casts(): array
    {
        return [
            'loan_product_id' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
