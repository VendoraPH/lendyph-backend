<?php

namespace App\Models;

use App\Exceptions\PostedJournalIsImmutableException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of one entry. Exactly one of `debit`/`credit` is non-zero, which the
 * database enforces with a CHECK rather than leaving to this class.
 *
 * Deliberately NOT Auditable. The journal header is, and a line has no life of
 * its own: it is created with a draft, frozen when that draft posts, and never
 * touched again. Auditing each line would triple the audit volume of the
 * busiest table in the system to record the same event the header already does.
 *
 * @property int $id
 * @property int $accounting_journal_id
 * @property int $accounting_account_id
 * @property int $line_no
 * @property string|null $description
 * @property int $debit
 * @property int $credit
 */
class AccountingJournalLine extends Model
{
    use HasFactory;

    protected $table = 'accounting_journal_lines';

    protected $fillable = [
        'accounting_journal_id',
        'accounting_account_id',
        'line_no',
        'description',
        'debit',
        'credit',
    ];

    protected function casts(): array
    {
        return [
            'accounting_journal_id' => 'integer',
            'accounting_account_id' => 'integer',
            'line_no' => 'integer',
            'debit' => 'integer',
            'credit' => 'integer',
        ];
    }

    /**
     * A posted entry's lines are frozen along with it.
     *
     * Without this, the header's immutability guard is worth very little: the
     * totals on a posted header would stay exactly as posted while the lines
     * beneath them said something else, and every report reads the LINES. The
     * header would look untouched and the books would have moved.
     *
     * Each hook costs one SELECT of the parent. That is paid on draft edits
     * only, which are rare and small; posting and reporting never write lines
     * through this model at all.
     */
    protected static function booted(): void
    {
        static::creating(fn (self $line) => self::refuseUnlessDraft($line, 'added to'));
        static::updating(fn (self $line) => self::refuseUnlessDraft($line, 'updated'));
        static::deleting(fn (self $line) => self::refuseUnlessDraft($line, 'removed from'));
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'accounting_journal_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'accounting_account_id');
    }

    /**
     * Read the parent's status straight from the table rather than through the
     * relation: the relation may be a stale in-memory copy loaded before the
     * post, and "is this journal a draft RIGHT NOW" is the whole question.
     */
    private static function refuseUnlessDraft(self $line, string $action): void
    {
        $journalId = (int) $line->accounting_journal_id;

        $status = AccountingJournal::query()
            ->whereKey($journalId)
            ->value('status');

        // No parent yet means a cascade delete took the header first, or the
        // FK is about to refuse the insert. Either way this guard has nothing
        // to say and the database has the last word.
        if ($status === null || $status === 'draft') {
            return;
        }

        throw PostedJournalIsImmutableException::line($journalId, (string) $status, $action);
    }
}
