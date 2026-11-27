<?php

namespace App\Models;

use Database\Factories\SavingsIdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A savings money post's `Idempotency-Key`, per user, with the request's hash
 * and the response it got, so a retry replays that response instead of
 * posting twice (IdempotentWrite). Written in the same transaction as the
 * post, so a failure leaves no row.
 *
 * @property int $id
 * @property int $user_id
 * @property string $key
 * @property string $request_hash
 * @property int|null $response_status
 * @property array<string, mixed>|null $response_body
 */
class SavingsIdempotencyKey extends Model
{
    /** @use HasFactory<SavingsIdempotencyKeyFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'key',
        'request_hash',
        'response_status',
        'response_body',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'response_status' => 'integer',
            'response_body' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
