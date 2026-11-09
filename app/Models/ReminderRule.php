<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * When a reminder fires, relative to a period's due date, on which channels,
 * for which loans, with which template type.
 *
 * @property list<string> $channels
 */
class ReminderRule extends Model
{
    use Auditable;

    public const TRIGGERS = ['before_due', 'on_due', 'after_due'];

    public const CHANNELS = ['sms', 'email'];

    public const BORROWER_TYPES = ['all', 'member', 'non_member'];

    protected $fillable = [
        'name',
        'trigger',
        'days',
        'send_time',
        'channels',
        'branch_id',
        'loan_product_id',
        'borrower_type',
        'template_type',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'channels' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Whether the rule covers a loan in this branch, on this product, for a
     * borrower with this status. A null branch or product covers all.
     */
    public function appliesTo(?int $branchId, ?int $productId, ?string $borrowerStatus): bool
    {
        if ($this->branch_id !== null && (int) $this->branch_id !== $branchId) {
            return false;
        }

        if ($this->loan_product_id !== null && (int) $this->loan_product_id !== $productId) {
            return false;
        }

        $isMember = ! in_array($borrowerStatus, Borrower::NON_MEMBER_STATUSES, true);

        return match ($this->borrower_type) {
            'member' => $isMember,
            'non_member' => ! $isMember,
            default => true,
        };
    }

    /**
     * Before-due and due-day reminders, the ones Auto-Pay collects for.
     */
    public function isBeforeOrOnDue(): bool
    {
        return in_array($this->trigger, ['before_due', 'on_due'], true);
    }

    /**
     * Where the rule sits in a period's life, for "the later rule wins": a
     * before-due rule counts negative, an after-due rule positive.
     */
    public function offsetDays(): int
    {
        return match ($this->trigger) {
            'before_due' => -$this->days,
            'after_due' => $this->days,
            default => 0,
        };
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(LoanProduct::class);
    }
}
