<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one loan product is offered online (origination settings, "products"):
 * whether it is, the extra application fields and documents it requires, and
 * the eligibility and instructions applicants read. Audited.
 *
 * @property list<string> $required_fields
 * @property list<string> $required_documents
 */
class OriginationProductSetting extends Model
{
    use Auditable;

    protected $fillable = ['loan_product_id', 'online', 'required_fields', 'eligibility', 'required_documents', 'instructions'];

    protected function casts(): array
    {
        return [
            'loan_product_id' => 'integer',
            'online' => 'boolean',
            'required_fields' => 'array',
            'required_documents' => 'array',
        ];
    }

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class);
    }
}
