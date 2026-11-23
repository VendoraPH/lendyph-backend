<?php

namespace App\Http\Resources\Amla;

use App\Models\AmlaCalendarEntry;
use App\Models\AmlaChangeRequest;
use App\Models\AmlaLogEntry;
use App\Models\AmlaSettingsVersion;
use App\Models\User;
use App\Services\Amla\Foundation\AmlaLog;
use App\Services\Amla\Foundation\AmlaSettings;
use App\Services\Amla\Foundation\ChangeRequests;

/**
 * How the AMLA endpoints present their rows: dates as YYYY-MM-DD, users as
 * `{id, name}` (null for the system), money as two-decimal strings with a
 * ₱ `value_words`, statuses, kinds and actions in words beside their keys.
 * Later AMLA slices reuse it.
 */
final class AmlaPresenter
{
    /** @return array{id: int, name: string}|null */
    public static function user(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $name = trim((string) $user->full_name);

        return ['id' => (int) $user->id, 'name' => $name !== '' ? $name : (string) $user->username];
    }

    /** @return array<string, mixed> */
    public static function settingsVersion(AmlaSettingsVersion $version): array
    {
        $settings = [];

        foreach (AmlaSettings::DEFINITIONS as $key => $definition) {
            $value = $version->values[$key] ?? null;
            $settings[] = [
                'key' => $key,
                'label' => $definition['label'],
                'unit' => $definition['unit'],
                'value' => $value,
                'value_words' => $value === null ? null : AmlaSettings::valueWords($key, $value),
                'to_verify' => $version->isToVerify($key),
                'source' => $version->sources[$key] ?? null,
            ];
        }

        return [
            'id' => (int) $version->id,
            'version' => $version->version,
            'source' => $version->request_id === null ? 'system_default' : 'approved_change',
            'effective_at' => $version->effective_at,
            'requested_by' => self::user($version->creator),
            'approved_by' => self::user($version->approver),
            'request_id' => $version->request_id,
            'to_verify_count' => count($version->to_verify),
            'settings' => $settings,
        ];
    }

    /** @return array<string, mixed> */
    public static function changeRequest(AmlaChangeRequest $request): array
    {
        return [
            'id' => (int) $request->id,
            'type' => $request->type,
            'type_words' => ChangeRequests::TYPE_WORDS[$request->type] ?? $request->type,
            'status' => $request->status,
            'status_words' => ChangeRequests::STATUS_WORDS[$request->status] ?? $request->status,
            'summary' => ChangeRequests::summary($request),
            'payload' => $request->payload,
            'reason' => $request->reason,
            'requested_by' => self::user($request->requester),
            'requested_at' => $request->created_at,
            'decided_by' => self::user($request->decider),
            'decided_at' => $request->decided_at,
            'decision_note' => $request->decision_note,
        ];
    }

    /** @return array<string, mixed> */
    public static function calendarEntry(AmlaCalendarEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'date' => $entry->date->toDateString(),
            'kind' => $entry->kind,
            'kind_words' => AmlaCalendarEntry::KINDS[$entry->kind] ?? $entry->kind,
            'name' => $entry->name,
            'source' => $entry->source,
            'request_id' => $entry->request_id,
            'approved_by' => self::user($entry->request?->decider),
            'added_at' => $entry->created_at,
        ];
    }

    /** @return array<string, mixed> */
    /** @param  array<string, string>  $references  AmlaLog::references() for the page */
    public static function logEntry(AmlaLogEntry $entry, array $references = []): array
    {
        return [
            'id' => (int) $entry->id,
            'at' => $entry->created_at,
            'action' => $entry->action,
            'action_words' => AmlaLog::actionWords($entry->action),
            'actor_type' => $entry->actor_type,
            'actor' => self::user($entry->user),
            'subject_type' => $entry->subject_type,
            'subject_id' => $entry->subject_id,
            'subject_words' => AmlaLog::subjectWords($entry->subject_type, $entry->subject_id, $references["{$entry->subject_type}:{$entry->subject_id}"] ?? null),
            'details' => $entry->details,
            'ip_address' => $entry->ip_address,
        ];
    }
}
