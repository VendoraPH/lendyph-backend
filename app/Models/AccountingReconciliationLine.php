<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line off the external statement, and the ledger line it was paired with.
 *
 * `matched_journal_line_id` is a DECISION — a person said these two are the
 * same event. The engine's suggestions are never written here; they are
 * recomputed on every read, because a suggestion that persisted would become
 * indistinguishable from a decision.
 *
 * @property int $id
 * @property int $accounting_reconciliation_id
 * @property string $date
 * @property string $description
 * @property int $amount Centavos, signed. Positive is money in.
 * @property string|null $external_reference
 * @property int|null $matched_journal_line_id
 * @property int|null $created_by
 */
class AccountingReconciliationLine extends Model
{
    use Auditable, HasFactory;

    protected $table = 'accounting_reconciliation_lines';

    protected $fillable = [
        'accounting_reconciliation_id',
        'date',
        'description',
        'amount',
        'external_reference',
        'matched_journal_line_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'amount' => 'integer',
            'accounting_reconciliation_id' => 'integer',
            'matched_journal_line_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(AccountingReconciliation::class, 'accounting_reconciliation_id');
    }

    public function matchedJournalLine(): BelongsTo
    {
        return $this->belongsTo(AccountingJournalLine::class, 'matched_journal_line_id');
    }
}
