<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The deployment's one reminders settings row (seeded, switched off).
 *
 * Provider credentials are encrypted at rest, hidden from every array and JSON
 * form of the model, and never written to the audit trail. The engine always
 * runs in Asia/Manila and holds the contact hours within 06:00 to 22:00,
 * whatever is stored (App\Services\Reminders\ContactHours).
 */
class ReminderSetting extends Model
{
    use Auditable;

    public const TIMEZONE = 'Asia/Manila';

    public const MAX_ATTEMPTS = 3;

    /**
     * @var list<string>
     */
    protected array $auditRedacted = ['sms_api_key', 'sms_api_secret', 'email_api_key'];

    protected $hidden = ['sms_api_key', 'sms_api_secret', 'email_api_key'];

    protected $fillable = [
        'enabled',
        'timezone',
        'default_send_time',
        'sms_enabled',
        'email_enabled',
        'contact_hours_start',
        'contact_hours_end',
        'max_attempts',
        'retry_delay_minutes',
        'sms_provider',
        'sms_sender_id',
        'sms_api_key',
        'sms_api_secret',
        'sms_cost_per_part',
        'email_provider',
        'email_sender_name',
        'email_sender_email',
        'email_reply_to',
        'email_api_key',
        'payment_instructions',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'sms_enabled' => 'boolean',
            'email_enabled' => 'boolean',
            'max_attempts' => 'integer',
            'retry_delay_minutes' => 'integer',
            'sms_api_key' => 'encrypted',
            'sms_api_secret' => 'encrypted',
            'email_api_key' => 'encrypted',
            'sms_cost_per_part' => 'decimal:4',
        ];
    }

    /**
     * The settings row, or an unsaved switched-off default when the row is
     * missing, so reading the settings never writes.
     */
    public static function current(): self
    {
        return static::query()->orderBy('id')->first() ?? new static([
            'enabled' => false,
            'timezone' => self::TIMEZONE,
            'default_send_time' => '09:00:00',
            'sms_enabled' => true,
            'email_enabled' => true,
            'contact_hours_start' => '08:00:00',
            'contact_hours_end' => '18:00:00',
            'max_attempts' => self::MAX_ATTEMPTS,
            'retry_delay_minutes' => 15,
        ]);
    }

    public function channelEnabled(string $channel): bool
    {
        return match ($channel) {
            'sms' => (bool) $this->sms_enabled,
            'email' => (bool) $this->email_enabled,
            default => false,
        };
    }

    /**
     * Between 1 and 3, whatever is stored.
     */
    public function maxAttempts(): int
    {
        return max(1, min(self::MAX_ATTEMPTS, (int) $this->max_attempts));
    }

    public function retryDelayMinutes(): int
    {
        return max(1, (int) $this->retry_delay_minutes);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
