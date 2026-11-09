<?php

/*
 * Payment reminders: the deployment's switches.
 *
 * Every default here is safe with no `.env` change. Nothing in this codebase
 * talks to an SMS or email provider: the only drivers are `log` (one log line,
 * nothing leaves the server) and `fake` (tests). Any other driver name reaches
 * App\Services\Reminders\Drivers\UnavailableDriver, which marks the reminder
 * "Sending is not set up" and logs a critical error. A real provider is added
 * only after the cooperative's owner signs off on it.
 *
 * The cooperative's own on/off switch is `reminder_settings.enabled`, which
 * starts off. These switches sit above it, per deployment.
 */

$list = static fn (?string $value): array => array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn (string $entry) => $entry !== ''));

return [

    /*
     * Which driver hands over each channel's messages: `log` or, in tests,
     * `fake`.
     */
    'drivers' => [
        'sms' => env('REMINDERS_SMS_DRIVER', 'log'),
        'email' => env('REMINDERS_EMAIL_DRIVER', 'log'),
    ],

    /*
     * Off: every message is a test message (`reminder_messages.test_mode`).
     * Needed, with a real driver, before anything could ever reach a member.
     */
    'live_sending' => filter_var(env('REMINDERS_LIVE_SENDING', false), FILTER_VALIDATE_BOOL),

    /*
     * The only recipients any driver is handed while `allow_all_recipients` is
     * off: staff mobile numbers (any Philippine format) and email addresses,
     * comma separated. Empty means nobody, so a fresh deployment hands nothing
     * to any driver. Everyone else is skipped, "Recipient is not on the
     * allow-list".
     */
    'allow_list' => $list(env('REMINDERS_ALLOW_LIST', '')),

    'allow_all_recipients' => filter_var(env('REMINDERS_ALLOW_ALL_RECIPIENTS', false), FILTER_VALIDATE_BOOL),

    /*
     * How many days ahead `reminders:plan` plans, counting today. Rows planned
     * ahead show as upcoming and are cancelled if the period is paid first.
     */
    'plan_days_ahead' => 7,

    'dispatch' => [
        // One `reminders:dispatch` run stops after this long or this many
        // reminders, so each minute's run ends before the next one starts.
        'max_seconds' => 50,
        'max_messages' => 200,
        // A row claimed longer ago than this belongs to a run that died.
        'stale_claim_minutes' => 10,
    ],

    /*
     * Recipient, subject and text are blanked this many years after the
     * loan closed (`reminders:redact-messages`).
     */
    'retention_years' => 2,

];
