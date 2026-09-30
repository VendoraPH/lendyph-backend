<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ShareCapitalLedger extends Model
{
    use HasFactory;

    protected $table = 'share_capital_ledger';

    protected $fillable = [
        'borrower_id',
        'repayment_id',
        'date',
        'description',
        'reference',
        'debit',
        'credit',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ShareCapitalLedger $entry) {
            if (empty($entry->reference)) {
                $dateStr = Carbon::parse($entry->date ?? now())->format('Ymd');
                $lastRef = static::where('reference', 'like', "SC-{$dateStr}-%")
                    ->orderByDesc('id')
                    ->value('reference');
                $nextNum = $lastRef ? (int) substr($lastRef, -6) + 1 : 1;
                $entry->reference = 'SC-'.$dateStr.'-'.str_pad($nextNum, 6, '0', STR_PAD_LEFT);
            }
        });
    }

    /**
     * Each member's share capital balance, for any number of members in one query.
     *
     * The balance is `SUM(credit) - SUM(debit)` across the member's whole
     * ledger: no date filter and no clamp, so it can be negative. That is the
     * figure RepaymentService::reverseShareCapitalCredit() judges a void against
     * and the Share Capital report shows; this is the same expression, grouped,
     * so a list can value every member it holds with one aggregate instead of
     * one per row.
     *
     * Every id asked about is in the result. A member with no ledger rows has a
     * balance of 0, not an unknown one.
     *
     * @param  array<int, int>  $borrowerIds
     * @return array<int, float> balance rounded to centavos, keyed by borrower_id
     */
    public static function balancesFor(array $borrowerIds): array
    {
        $borrowerIds = array_values(array_unique($borrowerIds));

        if ($borrowerIds === []) {
            return [];
        }

        $balances = array_fill_keys($borrowerIds, 0.0);

        $sums = static::balancesQuery()
            ->whereIn('borrower_id', $borrowerIds)
            ->pluck('balance', 'borrower_id');

        foreach ($sums as $borrowerId => $balance) {
            $balances[(int) $borrowerId] = round((float) $balance, 2);
        }

        return $balances;
    }

    /**
     * The balance definition balancesFor() reads, as a query: one row per
     * member with ledger entries, `borrower_id` and `balance`.
     *
     * Exposed so a query that has to sort or total by the balance, like the
     * Collateral Register, can join it as a derived table instead of writing
     * a second definition of "balance" that could drift from this one. Narrow
     * it with a `where` on `borrower_id`; a member with no rows is absent, and
     * their balance is 0.
     */
    public static function balancesQuery(): QueryBuilder
    {
        return static::query()
            ->toBase()
            ->groupBy('borrower_id')
            ->selectRaw('borrower_id, COALESCE(SUM(credit) - SUM(debit), 0) as balance');
    }

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(Borrower::class);
    }

    public function repayment(): BelongsTo
    {
        return $this->belongsTo(Repayment::class);
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
