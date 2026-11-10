<?php

namespace App\Models;

use Database\Factories\CdaNoticeSentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A CDA reminder or notice already sent (CDA design §10), so each goes out
 * once: unique on subject, kind and `dedupe_key` (for a deadline milestone,
 * the milestone and the effective deadline, so an extension starts them
 * again). Not audited: the notices themselves are the record.
 */
class CdaNoticeSent extends Model
{
    /** @use HasFactory<CdaNoticeSentFactory> */
    use HasFactory;

    protected $table = 'cda_notices_sent';

    public $timestamps = false;

    protected $fillable = ['subject_type', 'subject_id', 'kind', 'dedupe_key', 'sent_at'];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'sent_at' => 'datetime',
        ];
    }
}
