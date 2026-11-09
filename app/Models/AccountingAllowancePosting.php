<?php

namespace App\Models;

use App\Services\Accounting\AllowancePoster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One branch's allowance for credit losses in one allowance run (decision
 * 12), and the `credit_loss` journal that posted it. Written only by
 * {@see AllowancePoster}.
 *
 * Money is integer centavos. `adjustment` = `required` − `previous_balance`:
 * positive provides (Dr credit loss expense / Cr 1200), negative releases.
 *
 * @property int $id
 * @property string $run_id
 * @property Carbon $as_of
 * @property int $branch_id
 * @property int $required
 * @property int $previous_balance
 * @property int $adjustment
 * @property int|null $accounting_journal_id
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AccountingAllowancePosting extends Model
{
    protected $fillable = [
        'run_id',
        'as_of',
        'branch_id',
        'required',
        'previous_balance',
        'adjustment',
        'accounting_journal_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'as_of' => 'date',
            'branch_id' => 'integer',
            'required' => 'integer',
            'previous_balance' => 'integer',
            'adjustment' => 'integer',
            'accounting_journal_id' => 'integer',
            'created_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<AccountingJournal, $this> */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(AccountingJournal::class, 'accounting_journal_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The posting as the API answers it. `$listed` adds what the postings
     * list shows beside each row: its run, date, author and time.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(bool $listed = false): array
    {
        $row = [
            'id' => $this->id,
            'branch' => $this->branch === null ? null : ['id' => $this->branch->id, 'name' => $this->branch->name],
            'required' => $this->required,
            'previous_balance' => $this->previous_balance,
            'adjustment' => $this->adjustment,
            'journal' => $this->journal === null ? null : ['id' => $this->journal->id, 'journal_no' => $this->journal->journal_no],
        ];

        if (! $listed) {
            return $row;
        }

        return [
            ...$row,
            'run_id' => $this->run_id,
            'as_of' => $this->as_of->toDateString(),
            'created_by' => $this->creator === null ? null : ['id' => $this->creator->id, 'name' => $this->creator->full_name],
            'created_at' => $this->created_at,
        ];
    }
}
