<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One print of a loan's disclosure statement or promissory note, with the
 * terms the document stated at that moment (finish run round 3, "Reprint
 * after a change"). Written by POST /loans/{loan}/print-log beside its
 * `printed` audit row, and never changed afterwards.
 *
 * `terms_fingerprint` is LoanDocumentTerms::fingerprint() at the print, or
 * null when the document couldn't be built then; `terms_version` is
 * LoanDocumentTerms::VERSION at the print. LoanDocumentPrints compares the
 * latest print of each document with the loan's terms now.
 *
 * @property int $id
 * @property int $loan_id
 * @property string $document
 * @property string|null $terms_fingerprint
 * @property int $terms_version
 * @property int $printed_by
 * @property Carbon $printed_at
 */
class LoanDocumentPrint extends Model
{
    public const DISCLOSURE = 'disclosure';

    public const PROMISSORY_NOTE = 'promissory_note';

    /**
     * Each document, in the order the loan page and the release refusal name
     * them, with the name it is shown by.
     *
     * @var array<string, string>
     */
    public const LABELS = [
        self::DISCLOSURE => 'Disclosure Statement',
        self::PROMISSORY_NOTE => 'Promissory Note',
    ];

    /** Rows are written once; `printed_at` is the only time kept. */
    public $timestamps = false;

    protected $fillable = [
        'loan_id',
        'document',
        'terms_fingerprint',
        'terms_version',
        'printed_by',
        'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'terms_version' => 'integer',
            'printed_at' => 'datetime',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function printedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }
}
