<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Collateral extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'borrower_id',
        'collateral_type_id',
        'detail_value',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function collateralType(): BelongsTo
    {
        return $this->belongsTo(CollateralType::class);
    }

    public function loans(): BelongsToMany
    {
        return $this->belongsToMany(Loan::class, 'loan_collaterals')
            ->withPivot(['snapshot_value', 'attached_at'])
            ->withTimestamps();
    }

    /**
     * The loans currently holding this collateral, narrowed to Loan::ACTIVE_STATUSES.
     *
     * This is the single source of the "is this collateral already pledged?"
     * answer: CollateralResource renders it as `active_loans` and
     * CollateralController::attach() guards on it. It is a many-to-many on
     * purpose — a collateral can legitimately end up on two active loans, e.g.
     * when a draft loan holding it is released while another active loan already
     * does, a transition the attach guard does not see.
     *
     * Always eager-load it (`with('activeLoans')`); the collateral index is
     * unpaginated, so a lazy access there is one query per row.
     *
     * Only the two columns the resource exposes are selected, so the loans
     * hydrated here are deliberately partial models — do not read any other
     * attribute off them.
     */
    public function activeLoans(): BelongsToMany
    {
        return $this->belongsToMany(Loan::class, 'loan_collaterals')
            ->select(['loans.id', 'loans.loan_account_number'])
            ->whereIn('loans.status', Loan::ACTIVE_STATUSES);
    }
}
