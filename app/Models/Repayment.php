<?php

namespace App\Models;

use App\Exceptions\MalformedSequenceCodeException;
use App\Services\SequenceCode;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Repayment extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'receipt_number',
        'loan_id',
        'payment_date',
        'method',
        'reference_number',
        'amount_paid',
        'principal_applied',
        'interest_applied',
        'penalty_applied',
        'insurance_applied',
        'overdue_interest_applied',
        'current_interest_applied',
        'current_principal_applied',
        'next_interest_applied',
        'next_principal_applied',
        'overpayment',
        'balance_before',
        'balance_after',
        'payment_type',
        'status',
        'void_reason',
        'voided_by',
        'voided_at',
        'received_by',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount_paid' => 'decimal:2',
            'principal_applied' => 'decimal:2',
            'interest_applied' => 'decimal:2',
            'penalty_applied' => 'decimal:2',
            'insurance_applied' => 'decimal:2',
            'overdue_interest_applied' => 'decimal:2',
            'current_interest_applied' => 'decimal:2',
            'current_principal_applied' => 'decimal:2',
            'next_interest_applied' => 'decimal:2',
            'next_principal_applied' => 'decimal:2',
            'overpayment' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * The unique index two payments recorded at once can both reach for the
     * same receipt number on. The writes that record a payment name it as
     * LoanWriteTransaction::run()'s `racedUniqueIndex`, so losing that race
     * answers 409 rather than 500.
     */
    public const RECEIPT_NUMBER_INDEX = 'repayments_receipt_number_unique';

    public const RECEIPT_PREFIX = 'RCP';

    /** What a preview's rolled-back repayment is numbered with instead. */
    private const PREVIEW_PREFIX = 'PREVIEW-';

    /**
     * The next receipt number, read from the newest repayment with a locking
     * read, as Loan::booted() reads the newest application number.
     *
     * Payments on different loans hold different loan locks, so without the
     * lock two of them read the same newest receipt and number themselves
     * alike; the second then hit the unique index and answered 500. The lock
     * makes the second wait for the first; what ordering cannot rule out
     * still lands on RECEIPT_NUMBER_INDEX, which the callers turn into a 409.
     *
     * A preview's repayment (previewReceiptNumber()) keeps its placeholder and
     * takes no lock, so a preview never waits on, or collides with, a real
     * payment's number.
     */
    protected static function booted(): void
    {
        static::creating(function (Repayment $repayment) {
            if (str_starts_with((string) $repayment->receipt_number, self::PREVIEW_PREFIX)) {
                return;
            }

            $repayment->receipt_number = static::nextReceiptNumber();
        });
    }

    /**
     * A placeholder receipt for a repayment a preview records and rolls back
     * (RepaymentService::recordRepaymentOnLockedLoan() in preview mode):
     * unique, so two previews never meet on the index, and within the
     * column's 20 characters.
     */
    public static function previewReceiptNumber(): string
    {
        return self::PREVIEW_PREFIX.Str::upper(Str::random(12));
    }

    /**
     * RCP- plus one after the newest repayment's number, parsed with
     * SequenceCode rather than cast, under a lock on that row.
     *
     * A malformed newest receipt never stops a payment. SequenceCode refuses
     * to derive a number from it, so the next number comes from the highest
     * well-formed RCP- receipt instead (RCP-000001 when there is none), and
     * the bad row is logged so it can be corrected. Restarting from it, as the
     * old `(int) substr()` did, would have collided with RCP-000001 forever.
     */
    private static function nextReceiptNumber(): string
    {
        $newest = static::query()->orderByDesc('id')->lockForUpdate()->first(['id', 'receipt_number']);

        if ($newest === null) {
            return SequenceCode::first(self::RECEIPT_PREFIX);
        }

        try {
            return SequenceCode::after(self::RECEIPT_PREFIX, $newest->receipt_number, "repayments.id {$newest->id}");
        } catch (MalformedSequenceCodeException) {
            Log::warning('Malformed newest receipt number; numbering from the highest well-formed one.', [
                'repayment_id' => $newest->id,
                'receipt_number' => $newest->receipt_number,
            ]);

            $highest = static::query()
                ->whereRaw('receipt_number REGEXP ?', ['^'.self::RECEIPT_PREFIX.'-[0-9]+$'])
                ->orderByRaw('CAST(SUBSTRING(receipt_number, ?) AS UNSIGNED) DESC', [strlen(self::RECEIPT_PREFIX) + 2])
                ->value('receipt_number');

            return $highest === null
                ? SequenceCode::first(self::RECEIPT_PREFIX)
                : SequenceCode::after(self::RECEIPT_PREFIX, $highest, 'the highest well-formed receipt');
        }
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(RepaymentAllocation::class);
    }

    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function shareCapitalLedgerEntries(): HasMany
    {
        return $this->hasMany(ShareCapitalLedger::class);
    }
}
