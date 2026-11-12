<?php

namespace App\Models;

use App\Traits\Auditable;
use Database\Factories\SavingsApprovalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A maker-checker request (design §1, §4): a withdrawal or close above tier 1,
 * a time deposit payout or pre-termination, or any reversal. While pending,
 * an outflow reduces the available balance. ApprovalGate opens it; an
 * `Approvals\{Kind}Executor` carries it out once approved.
 *
 * @property int $id
 * @property string $kind
 * @property int|null $account_id
 * @property int|null $time_deposit_id
 * @property int|null $target_transaction_id
 * @property string $amount
 * @property string $fee_amount the excess-withdrawal fee it reserves beside `amount` (₱0.00 when none)
 * @property array<string, mixed>|null $payload
 * @property int $required_checkers
 * @property string $status
 * @property int $requested_by
 * @property int|null $first_checker_id
 * @property Carbon|null $expires_at
 */
class SavingsApproval extends Model
{
    /** @use HasFactory<SavingsApprovalFactory> */
    use Auditable, HasFactory;

    /**
     * `share_capital_transfer` and `reactivation` are registered for the gap
     * round's later slices; until their executors exist, approving one
     * answers 422 in words (ApprovalGate::executor()).
     */
    public const KINDS = ['withdrawal', 'close', 'td_payout', 'pretermination', 'reversal', 'share_capital_transfer', 'reactivation'];

    /**
     * Kinds whose amount (plus `fee_amount`) leaves the account while
     * pending. A reversal does when it reverses a credit.
     */
    public const OUTFLOW_KINDS = ['withdrawal', 'close', 'share_capital_transfer'];

    public const STATUSES = ['pending', 'approved', 'rejected', 'cancelled', 'expired'];

    protected $fillable = [
        'kind',
        'account_id',
        'time_deposit_id',
        'target_transaction_id',
        'amount',
        'fee_amount',
        'payload',
        'required_checkers',
        'status',
        'requested_by',
        'first_checker_id',
        'decided_by',
        'decision_reason',
        'decided_at',
        'expires_at',
        'live_key',
        'result_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'time_deposit_id' => 'integer',
            'target_transaction_id' => 'integer',
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'payload' => 'array',
            'required_checkers' => 'integer',
            'requested_by' => 'integer',
            'first_checker_id' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
            'result_transaction_id' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SavingsAccount::class, 'account_id');
    }

    public function timeDeposit(): BelongsTo
    {
        return $this->belongsTo(TimeDeposit::class);
    }

    public function targetTransaction(): BelongsTo
    {
        return $this->belongsTo(SavingsTransaction::class, 'target_transaction_id');
    }

    public function resultTransaction(): BelongsTo
    {
        return $this->belongsTo(SavingsTransaction::class, 'result_transaction_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function firstChecker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_checker_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
