<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The optional lending details staff record for one loan application:
 * other and business income, household expenses, outside debt, stability
 * details, verified references, income verification, and when the member
 * acknowledged the credit assessment notice. Every field is optional; a blank
 * field takes its factor out of the score rather than costing points.
 */
class CreditApplicationProfile extends Model
{
    use Auditable;

    public const INCOME_VERIFICATIONS = ['verified', 'failed'];

    protected $fillable = [
        'loan_id',
        'other_income',
        'business_income',
        'household_expenses',
        'outside_debt_payments',
        'outside_lenders_count',
        'employment_status',
        'business_start_date',
        'years_at_residence',
        'verified_references_count',
        'income_verification',
        'notice_version',
        'notice_acknowledged_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'other_income' => 'decimal:2',
            'business_income' => 'decimal:2',
            'household_expenses' => 'decimal:2',
            'outside_debt_payments' => 'decimal:2',
            'outside_lenders_count' => 'integer',
            'business_start_date' => 'date',
            'years_at_residence' => 'decimal:2',
            'verified_references_count' => 'integer',
            'notice_acknowledged_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
