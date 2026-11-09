<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One branch's closing entry in a year-end close (`branch_id` null for the
 * journals with no branch), and the postable of its `closing` journal.
 *
 * `accounting_journal_id` is null only inside the close's transaction,
 * between this row's insert and the post of its journal: the journal needs
 * this row as its postable first. Never edited after the close.
 *
 * @property int $id
 * @property int $accounting_year_close_id
 * @property int|null $branch_id
 * @property int|null $accounting_journal_id
 * @property int $net_surplus
 */
class AccountingYearCloseEntry extends Model
{
    protected $table = 'accounting_year_close_entries';

    protected $fillable = [
        'accounting_year_close_id',
        'branch_id',
        'accounting_journal_id',
        'net_surplus',
    ];

    protected function casts(): array
    {
        return [
            'accounting_year_close_id' => 'integer',
            'branch_id' => 'integer',
            'accounting_journal_id' => 'integer',
            'net_surplus' => 'integer',
        ];
    }

    public function yearClose(): BelongsTo
    {
        return $this->belongsTo(AccountingYearClose::class, 'accounting_year_close_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'accounting_journal_id');
    }
}
