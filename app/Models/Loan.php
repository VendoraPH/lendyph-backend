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
        'start_date',
        'maturity_date',
        'deductions',
        'total_deductions',
        'net_proceeds',
        'penalty_rate',
        'grace_period_days',
        'status',
        'approval_remarks',
        'approved_by',
        'approved_at',
        'released_by',
        'released_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'interest_rate' => 'decimal:4',
            'principal_amount' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'net_proceeds' => 'decimal:2',
            'penalty_rate' => 'decimal:4',
            'start_date' => 'date',
            'maturity_date' => 'date',
            'approved_at' => 'datetime',
            'released_at' => 'datetime',
            'deductions' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Loan $loan) {
            $lastCode = static::query()->orderByDesc('id')->value('application_number');
            $nextNum = $lastCode ? (int) substr($lastCode, 3) + 1 : 1;
            $loan->application_number = 'LA-' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
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

    public function amortizationSchedules(): HasMany
    {
        return $this->hasMany(AmortizationSchedule::class)->orderBy('period_number');
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
