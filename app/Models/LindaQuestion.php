<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One question asked of Linda (Linda v1 plan, "Privacy and the log").
 *
 * Holds no question or answer text, by design, and is deliberately NOT
 * Auditable: an audit row of it would copy nothing useful and would put Linda
 * activity in the audit trail, which is kept forever, while this log is kept
 * `linda.retention_days` days (`linda:prune`).
 *
 * `branch_scope` is null for a caller who sees every branch, else the list of
 * branch ids they could see. `member_ids` lists the members the answer named.
 *
 * @property int $id
 * @property int|null $user_id
 * @property Carbon $asked_at
 * @property string $source
 * @property string|null $intent
 * @property string|null $answer_function
 * @property string|null $outcome
 * @property string|null $error_code
 * @property Carbon|null $period_from
 * @property Carbon|null $period_to
 * @property list<int>|null $branch_scope
 * @property int|null $branch_id
 * @property list<int>|null $member_ids
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property string $cost
 * @property int $duration_ms
 */
class LindaQuestion extends Model
{
    /** Answered by the deterministic matcher; nothing left the server. */
    public const SOURCE_MATCHER = 'matcher';

    /** Understood by the model. */
    public const SOURCE_MODEL = 'model';

    /** Neither understood it (the none driver, or a refusal before any call). */
    public const SOURCE_NONE = 'none';

    protected $fillable = [
        'user_id',
        'asked_at',
        'source',
        'intent',
        'answer_function',
        'outcome',
        'error_code',
        'period_from',
        'period_to',
        'branch_scope',
        'branch_id',
        'member_ids',
        'model',
        'input_tokens',
        'output_tokens',
        'cache_read_tokens',
        'cache_write_tokens',
        'cost',
        'duration_ms',
    ];

    /** The database defaults, so a row reads the same before and after it is saved. */
    protected $attributes = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'cache_read_tokens' => 0,
        'cache_write_tokens' => 0,
        'cost' => '0.000000',
        'duration_ms' => 0,
    ];

    protected function casts(): array
    {
        return [
            'asked_at' => 'datetime',
            'period_from' => 'date',
            'period_to' => 'date',
            'branch_scope' => 'array',
            'branch_id' => 'integer',
            'member_ids' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'cost' => 'decimal:6',
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
