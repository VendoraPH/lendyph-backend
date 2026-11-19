<?php

namespace App\Models;

use App\Traits\AmlaKeptForever;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in an AMLA case's own history (opened, assigned, note added,
 * evidence added, alert added, decided, STR prepared or filed). Details name
 * ids and statuses only, never note text or file names. Kept as written.
 *
 * @property int $id
 * @property int $case_id
 * @property string $action
 * @property int $user_id
 * @property array<string, mixed>|null $details
 */
class AmlaCaseAction extends Model
{
    use AmlaKeptForever;

    public const UPDATED_AT = null;

    /** @var array<string, string> */
    public const WORDS = [
        'opened' => 'Case opened',
        'assigned' => 'Assigned',
        'note_added' => 'Note added',
        'evidence_added' => 'Evidence added',
        'alert_added' => 'Alert added',
        'decided' => 'Decision recorded',
        'str_prepared' => 'STR prepared',
        'str_filed' => 'STR filed',
    ];

    protected $fillable = ['case_id', 'action', 'user_id', 'details'];

    protected function casts(): array
    {
        return ['case_id' => 'integer', 'user_id' => 'integer', 'details' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
