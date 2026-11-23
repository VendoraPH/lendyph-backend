<?php

namespace App\Models;

use App\Traits\OriginationAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message meant for an online applicant (LO-B3): an information request, a
 * document request, a decision, a status change or a resume reminder. It is
 * recorded, never sent: no driver can reach an applicant until the owner
 * signs (origination §6), so `delivery` is always `logged` and screens read
 * "Logged, not sent". The body is encrypted at rest. Append-only.
 */
class OriginationApplicantMessage extends Model
{
    use OriginationAppendOnly;

    public const UPDATED_AT = null;

    public const KINDS = ['information_request', 'document_request', 'status_change', 'decision', 'resume_reminder'];

    public const DELIVERY_LOGGED = 'logged';

    public const DELIVERY_LABELS = [
        'logged' => 'Logged, not sent',
        'sent' => 'Sent',
    ];

    public const KIND_LABELS = [
        'information_request' => 'Information request',
        'document_request' => 'Document request',
        'status_change' => 'Status update',
        'decision' => 'Decision',
        'resume_reminder' => 'Reminder to finish the application',
    ];

    protected $fillable = ['application_id', 'kind', 'channel', 'body', 'document_types', 'delivery', 'user_id'];

    protected function casts(): array
    {
        return [
            'application_id' => 'integer',
            'user_id' => 'integer',
            'body' => 'encrypted',
            'document_types' => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(OriginationApplication::class, 'application_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
