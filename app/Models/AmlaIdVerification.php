<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person's record of checking an ID (task §10: identity verification
 * tracking). A member's points at one of their own `valid_id` documents; a
 * walk-in's reads the ID on its record. Only the ID's type and last four
 * characters are kept here. Nothing claims an outside provider checked it:
 * v1 has none (design §6). Kept as written; notes are encrypted.
 *
 * @property int $id
 * @property int $profile_id
 * @property int|null $document_id
 * @property string $id_type
 * @property string|null $id_number_last4
 * @property string $method
 * @property string $result
 * @property Carbon|null $expires_on
 * @property string|null $notes
 * @property int $verified_by
 */
class AmlaIdVerification extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    /** @var array<string, string> */
    public const METHODS = [
        'original_seen' => 'Original ID seen in person',
        'certified_copy' => 'Certified true copy',
        'copy_only' => 'Photocopy or scan only',
    ];

    /** @var array<string, string> */
    public const RESULTS = [
        'verified' => 'Verified',
        'failed' => 'Could not be verified',
    ];

    protected $fillable = ['profile_id', 'document_id', 'id_type', 'id_number_last4', 'method', 'result', 'expires_on', 'notes', 'verified_by'];

    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'document_id' => 'integer',
            'expires_on' => 'date',
            'notes' => 'encrypted',
            'verified_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
