<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * What a reminder says, per type, channel and language. `subject` is email
 * only. The body may hold `{{variables}}` (App\Services\Reminders\ReminderRenderer).
 */
class ReminderTemplate extends Model
{
    use Auditable;

    public const TYPES = ['upcoming', 'due_today', 'past_due', 'long_past_due', 'partial_payment', 'payment_confirmation'];

    public const DEFAULT_LANGUAGE = 'en';

    protected $fillable = [
        'name',
        'type',
        'channel',
        'language',
        'subject',
        'body',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The active template for this type and channel in the language, falling
     * back to English.
     */
    public static function resolve(string $type, string $channel, ?string $language): ?self
    {
        $languages = array_values(array_unique(array_filter([$language, self::DEFAULT_LANGUAGE])));

        return static::query()
            ->where('type', $type)
            ->where('channel', $channel)
            ->where('is_active', true)
            ->whereIn('language', $languages)
            ->get()
            ->sortBy(fn (self $template) => array_search($template->language, $languages, true))
            ->first();
    }
}
