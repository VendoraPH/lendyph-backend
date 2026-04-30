<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Loan extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'loan_account_number',
        'borrower_id',
        'loan_product_id',
        'branch_id',
        'interest_rate',
        'interest_method',
        'term',
        'frequency',
        'principal_amount',
        'purpose',
        'start_date',
        'maturity_date',
        'deductions',
        'total_deductions',
        'net_proceeds',
        'scb_amount',
        'penalty_rate',
        'grace_period_days',
        'policy_exception',
        'policy_exception_details',
        'status',
        'approval_remarks',
        'approved_by',
        'approved_at',
        'rejection_remarks',
        'rejected_by',
        'rejected_at',
        'released_by',
        'released_at',
        'created_by',
        'account_officer_id',
        'auto_pay',
        'cbs_reference',
        'auto_pay_enabled_at',
        'auto_pay_enabled_by',
    ];

    protected function casts(): array
    {
        return [
            'interest_rate' => 'decimal:4',
            'principal_amount' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_proceeds' => 'decimal:2',
            'scb_amount' => 'decimal:2',
            'penalty_rate' => 'decimal:4',
            'policy_exception' => 'boolean',
            'start_date' => 'date',
            'maturity_date' => 'date',
            'approved_at' => 'datetime',
            'released_at' => 'datetime',
            'rejected_at' => 'datetime',
            'deductions' => 'array',
            'auto_pay' => 'boolean',
            'auto_pay_enabled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Loan $loan) {
            $last = static::query()->orderByDesc('id')->lockForUpdate()->first();
            $nextNum = $last ? (int) substr($last->application_number, 3) + 1 : 1;
            $loan->application_number = 'LA-'.str_pad($nextNum, 6, '0', STR_PAD_LEFT);
        });
    }

    protected function isEditable(): Attribute
    {
        return Attribute::get(fn () => in_array($this->status, ['draft', 'for_review']));
    }

    protected function isReleasable(): Attribute
    {
        return Attribute::get(fn () => $this->status === 'approved');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function coMakers(): BelongsToMany
    {
        return $this->belongsToMany(CoMaker::class, 'co_maker_loan')->withTimestamps();
    }

    public function collaterals(): BelongsToMany
    {
        return $this->belongsToMany(Collateral::class, 'loan_collaterals')
            ->withPivot(['snapshot_value', 'attached_at'])
            ->withTimestamps();
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function releasedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function rejectedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function accountOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_officer_id');
    }

    public function autoPayEnabledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auto_pay_enabled_by');
    }

    public function amortizationSchedules(): HasMany
    {
        return $this->hasMany(AmortizationSchedule::class)->orderBy('period_number');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(Repayment::class)->orderBy('payment_date');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(LoanAdjustment::class)->latest();
    }

    protected function outstandingBalance(): Attribute
    {
        return Attribute::get(function () {
            return $this->amortizationSchedules()
                ->selectRaw('SUM(principal_due - principal_paid) as balance')
                ->value('balance') ?? 0;
        });
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
