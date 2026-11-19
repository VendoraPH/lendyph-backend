<?php

namespace App\Models;

use App\Traits\Auditable;
use App\Traits\OriginationAppendOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A published version of a text an applicant accepts (origination decision
 * 9): the privacy notice, terms, declaration or credit authorization.
 * Append-only: the newest version of a type is its current one, and each
 * consent keeps the version and text hash it accepted. `is_final` marks the
 * owner's approved wording; anything else reads as draft wording.
 *
 * @property string $type
 * @property string $version
 * @property string $body
 * @property string $body_hash
 * @property bool $is_final
 */
class OriginationNotice extends Model
{
    use Auditable, OriginationAppendOnly;

    public const UPDATED_AT = null;

    public const TYPES = ['privacy_notice', 'terms', 'declaration', 'credit_authorization'];

    /** Notices an applicant must accept before an application can be taken. */
    public const REQUIRED_TYPES = ['privacy_notice', 'terms', 'declaration'];

    public const TYPE_LABELS = [
        'privacy_notice' => 'Privacy notice',
        'terms' => 'Terms and conditions',
        'declaration' => 'Declaration',
        'credit_authorization' => 'Credit authorization',
    ];

    /** The text itself stays out of audit rows; its hash and version are enough. */
    protected array $auditRedacted = ['body'];

    protected $fillable = ['type', 'version', 'title', 'body', 'body_hash', 'is_final', 'published_by', 'published_at'];

    protected function casts(): array
    {
        return [
            'is_final' => 'boolean',
            'published_by' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function describeAuditEvent(string $action): ?string
    {
        return $action === 'created'
            ? sprintf('%s version %s published%s', self::TYPE_LABELS[$this->type] ?? 'Notice', $this->version, $this->is_final ? ' as final wording' : ' as draft wording')
            : null;
    }
}
