<?php

namespace App\Services\Amla\Foundation;

use App\Models\AmlaCalendarEntry;
use App\Models\AmlaCalendarYear;
use App\Models\AmlaChangeRequest;
use App\Models\AmlaSettingsVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maker-checker for the AMLA settings and calendar (AMLA design §4.13): a
 * change is asked for with a reason, and applies only once a second
 * `amla:settings` holder approves it. The maker can withdraw it; another
 * holder can reject it with a note. One request waits per target at a time
 * (`pending_key`, unique while pending).
 *
 * - settings: the full next values, built on the version current when asked
 *   for; approving adds the next version, in force from approval.
 * - calendar_add: a holiday, AMLC non-reporting day or facility suspension,
 *   with its source.
 * - calendar_remove: takes an entry out of force; its row stays.
 * - calendar_confirm_year: confirms a year's calendar, so its deadlines are
 *   no longer provisional.
 *
 * Every step writes the AMLA log, never the main audit trail. Callers check
 * `amla:settings` and head-office access first (AmlaAccess).
 */
final class ChangeRequests
{
    /** @var array<string, string> */
    public const TYPE_WORDS = [
        AmlaChangeRequest::SETTINGS => 'AMLA settings',
        AmlaChangeRequest::CALENDAR_ADD => 'Add a calendar day',
        AmlaChangeRequest::CALENDAR_REMOVE => 'Remove a calendar day',
        AmlaChangeRequest::CALENDAR_CONFIRM_YEAR => 'Confirm a calendar year',
    ];

    /** @var array<string, string> */
    public const STATUS_WORDS = [
        AmlaChangeRequest::PENDING => 'Waiting for approval',
        AmlaChangeRequest::APPROVED => 'Approved',
        AmlaChangeRequest::REJECTED => 'Rejected',
        AmlaChangeRequest::WITHDRAWN => 'Withdrawn',
    ];

    /** @var array<string, string> each calendar kind with its article, for sentences */
    private const KIND_IN_A_SENTENCE = [
        'holiday' => 'a holiday',
        'amlc_non_reporting' => 'an AMLC non-reporting day',
        'facility_suspension' => 'a facility suspension',
    ];

    public function __construct(
        private readonly AmlaSettings $settings,
        private readonly AmlaLog $log,
        private readonly AmlaAccess $access,
    ) {}

    /**
     * Asks for a change. `$input` is the validated request: `type`, `reason`,
     * and the type's own fields.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException 422 in words when it changes nothing or one is already waiting
     */
    public function ask(User $maker, array $input): AmlaChangeRequest
    {
        $type = (string) $input['type'];

        return DB::transaction(function () use ($maker, $input, $type) {
            [$payload, $pendingKey, $duplicate] = match ($type) {
                AmlaChangeRequest::SETTINGS => $this->settingsPayload($input),
                AmlaChangeRequest::CALENDAR_ADD => $this->calendarAddPayload($input),
                AmlaChangeRequest::CALENDAR_REMOVE => $this->calendarRemovePayload($input),
                AmlaChangeRequest::CALENDAR_CONFIRM_YEAR => $this->confirmYearPayload($input),
            };

            try {
                $request = AmlaChangeRequest::query()->create([
                    'type' => $type,
                    'status' => AmlaChangeRequest::PENDING,
                    'pending_key' => $pendingKey,
                    'payload' => $payload,
                    'reason' => (string) $input['reason'],
                    'requested_by' => $maker->id,
                ]);
            } catch (QueryException $exception) {
                throw $this->isDuplicate($exception) ? $duplicate : $exception;
            }

            $this->log->record('change_requested', $maker, 'change_request', $request->id, ['type' => $type]);

            return $request;
        });
    }

    /**
     * Approves and applies a pending change. The maker never approves (403,
     * logged). A request that is no longer pending, or whose settings moved
     * on, answers 409 and nothing applies.
     */
    public function approve(User $checker, AmlaChangeRequest $request, ?string $note = null): AmlaChangeRequest
    {
        if ($request->requested_by === $checker->id) {
            $this->access->refuse($checker, 'maker_cannot_approve', 'You asked for this change, so another AMLA settings holder must approve it.');
        }

        return DB::transaction(function () use ($checker, $request, $note) {
            $locked = $this->lockPending($request);

            $applied = match ($locked->type) {
                AmlaChangeRequest::SETTINGS => $this->applySettings($locked, $checker),
                AmlaChangeRequest::CALENDAR_ADD => $this->applyCalendarAdd($locked),
                AmlaChangeRequest::CALENDAR_REMOVE => $this->applyCalendarRemove($locked),
                AmlaChangeRequest::CALENDAR_CONFIRM_YEAR => $this->applyConfirmYear($locked),
            };

            $this->decide($locked, AmlaChangeRequest::APPROVED, $checker, $note);
            $this->log->record('change_approved', $checker, 'change_request', $locked->id, ['type' => $locked->type, ...$applied]);

            return $locked;
        });
    }

    /** Rejects a pending change, with a note. The maker withdraws instead (403, logged). */
    public function reject(User $checker, AmlaChangeRequest $request, string $note): AmlaChangeRequest
    {
        if ($request->requested_by === $checker->id) {
            $this->access->refuse($checker, 'maker_cannot_reject', 'You asked for this change, so withdraw it instead of rejecting it.');
        }

        return DB::transaction(function () use ($checker, $request, $note) {
            $locked = $this->lockPending($request);
            $this->decide($locked, AmlaChangeRequest::REJECTED, $checker, $note);
            $this->log->record('change_rejected', $checker, 'change_request', $locked->id, ['type' => $locked->type]);

            return $locked;
        });
    }

    /** Withdraws a pending change: its maker only (403, logged). */
    public function withdraw(User $maker, AmlaChangeRequest $request, ?string $note = null): AmlaChangeRequest
    {
        if ($request->requested_by !== $maker->id) {
            $this->access->refuse($maker, 'not_the_maker', 'Only the person who asked for this change can withdraw it.');
        }

        return DB::transaction(function () use ($maker, $request, $note) {
            $locked = $this->lockPending($request);
            $this->decide($locked, AmlaChangeRequest::WITHDRAWN, $maker, $note);
            $this->log->record('change_withdrawn', $maker, 'change_request', $locked->id, ['type' => $locked->type]);

            return $locked;
        });
    }

    /** The change in words, for lists: "AMLA settings: CTR threshold, STR deadline". */
    public static function summary(AmlaChangeRequest $request): string
    {
        $payload = $request->payload;

        return match ($request->type) {
            AmlaChangeRequest::SETTINGS => 'AMLA settings: '.implode(', ', array_map(
                fn (string $key) => AmlaSettings::DEFINITIONS[$key]['short'] ?? $key,
                array_keys([...($payload['changed'] ?? []), ...array_flip($payload['verified'] ?? []), ...array_flip($payload['unverified'] ?? [])]),
            )),
            AmlaChangeRequest::CALENDAR_ADD => sprintf('Add %s (%s) on %s', $payload['name'], AmlaCalendarEntry::KINDS[$payload['kind']] ?? $payload['kind'], self::dateWords($payload['date'])),
            AmlaChangeRequest::CALENDAR_REMOVE => sprintf('Remove %s (%s) on %s', $payload['name'], AmlaCalendarEntry::KINDS[$payload['kind']] ?? $payload['kind'], self::dateWords($payload['date'])),
            AmlaChangeRequest::CALENDAR_CONFIRM_YEAR => "Confirm the {$payload['year']} AMLA calendar",
            default => $request->type,
        };
    }

    /**
     * The year a calendar request is about, or null for settings.
     */
    public static function calendarYear(AmlaChangeRequest $request): ?int
    {
        return match ($request->type) {
            AmlaChangeRequest::CALENDAR_ADD, AmlaChangeRequest::CALENDAR_REMOVE => (int) substr((string) $request->payload['date'], 0, 4),
            AmlaChangeRequest::CALENDAR_CONFIRM_YEAR => (int) $request->payload['year'],
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: string, 2: ValidationException}
     */
    private function settingsPayload(array $input): array
    {
        $duplicate = ValidationException::withMessages(['values' => 'A change to the AMLA settings is already waiting for approval.']);

        if (AmlaChangeRequest::query()->where('pending_key', AmlaChangeRequest::SETTINGS)->exists()) {
            throw $duplicate;
        }

        $current = $this->settings->current();
        $values = $current->values;
        $changed = [];

        foreach ((array) ($input['values'] ?? []) as $key => $value) {
            $value = AmlaSettings::normalise($key, $value);

            if ($value !== $values[$key]) {
                $changed[$key] = ['from' => $values[$key], 'to' => $value];
                $values[$key] = $value;
            }
        }

        $verified = array_keys((array) ($input['verified'] ?? []));
        $unverified = array_values((array) ($input['to_verify'] ?? []));
        $toVerify = array_values(array_unique([...array_diff($current->to_verify, $verified), ...$unverified]));
        $toVerify = array_values(array_intersect(array_keys(AmlaSettings::DEFINITIONS), $toVerify));
        $sources = $current->sources;

        foreach ((array) ($input['verified'] ?? []) as $key => $source) {
            $sources[$key] = trim((string) $source);
        }

        foreach ($unverified as $key) {
            unset($sources[$key]);
        }

        if ($changed === [] && $toVerify === $current->to_verify && $sources == $current->sources) {
            throw ValidationException::withMessages(['values' => 'This changes nothing in the current AMLA settings.']);
        }

        return [[
            'base_version' => $current->version,
            'values' => $values,
            'to_verify' => $toVerify,
            'sources' => (object) $sources,
            'changed' => (object) $changed,
            'verified' => array_values(array_intersect($verified, $current->to_verify)),
            'unverified' => array_values(array_diff($unverified, $current->to_verify)),
        ], AmlaChangeRequest::SETTINGS, $duplicate];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: string, 2: ValidationException}
     */
    private function calendarAddPayload(array $input): array
    {
        $date = (string) $input['date'];
        $kind = (string) $input['kind'];
        $key = "calendar_add:{$date}:{$kind}";
        $duplicate = ValidationException::withMessages([
            'date' => self::dateWords($date).' is already on the AMLA calendar as '.self::KIND_IN_A_SENTENCE[$kind].', or waiting for approval.',
        ]);

        if (AmlaCalendarEntry::query()->active()->whereDate('date', $date)->where('kind', $kind)->exists()
            || AmlaChangeRequest::query()->where('pending_key', $key)->exists()) {
            throw $duplicate;
        }

        return [['date' => $date, 'kind' => $kind, 'name' => trim((string) $input['name']), 'source' => trim((string) $input['source'])], $key, $duplicate];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: string, 2: ValidationException}
     */
    private function calendarRemovePayload(array $input): array
    {
        $entry = AmlaCalendarEntry::query()->findOrFail((int) $input['entry_id']);
        $key = "calendar_remove:{$entry->id}";
        $duplicate = ValidationException::withMessages(['entry_id' => 'This calendar day is already removed, or its removal is waiting for approval.']);

        if ($entry->removed_at !== null || AmlaChangeRequest::query()->where('pending_key', $key)->exists()) {
            throw $duplicate;
        }

        return [['entry_id' => $entry->id, 'date' => $entry->date->toDateString(), 'kind' => $entry->kind, 'name' => $entry->name], $key, $duplicate];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: string, 2: ValidationException}
     */
    private function confirmYearPayload(array $input): array
    {
        $year = (int) $input['year'];
        $key = "calendar_confirm_year:{$year}";
        $duplicate = ValidationException::withMessages(['year' => "The {$year} AMLA calendar is already confirmed, or its confirmation is waiting for approval."]);

        if (AmlaCalendarYear::query()->where('year', $year)->exists() || AmlaChangeRequest::query()->where('pending_key', $key)->exists()) {
            throw $duplicate;
        }

        return [['year' => $year], $key, $duplicate];
    }

    /** The request, re-read under a row lock; 409 unless it is still pending. */
    private function lockPending(AmlaChangeRequest $request): AmlaChangeRequest
    {
        $locked = AmlaChangeRequest::query()->lockForUpdate()->findOrFail($request->id);

        abort_unless($locked->isPending(), 409, 'This change is already '.strtolower(self::STATUS_WORDS[$locked->status] ?? $locked->status).'.');

        return $locked;
    }

    private function decide(AmlaChangeRequest $request, string $status, User $user, ?string $note): void
    {
        $request->forceFill([
            'status' => $status,
            'pending_key' => null,
            'decided_by' => $user->id,
            'decided_at' => now(),
            'decision_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
        ])->save();
    }

    /** @return array<string, mixed> what the log records as applied */
    private function applySettings(AmlaChangeRequest $request, User $checker): array
    {
        $payload = $request->payload;
        $latest = AmlaSettingsVersion::query()->orderByDesc('version')->lockForUpdate()->firstOrFail();

        abort_unless(
            $latest->version === (int) $payload['base_version'],
            409,
            'The AMLA settings changed after this was asked for. Reject it and ask again from the current settings.',
        );

        try {
            $version = AmlaSettingsVersion::query()->create([
                'version' => $latest->version + 1,
                'values' => $payload['values'],
                'to_verify' => $payload['to_verify'],
                'sources' => (object) ($payload['sources'] ?? []),
                'effective_at' => now(),
                'request_id' => $request->id,
                'created_by' => $request->requested_by,
                'approved_by' => $checker->id,
            ]);
        } catch (QueryException $exception) {
            abort_if($this->isDuplicate($exception), 409, 'The AMLA settings changed after this was asked for. Reject it and ask again from the current settings.');

            throw $exception;
        }

        return [
            'settings_version' => $version->version,
            'changed' => $payload['changed'] ?? [],
            'verified' => $payload['verified'] ?? [],
            'unverified' => $payload['unverified'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function applyCalendarAdd(AmlaChangeRequest $request): array
    {
        $payload = $request->payload;

        try {
            $entry = AmlaCalendarEntry::query()->create([
                'date' => $payload['date'],
                'kind' => $payload['kind'],
                'name' => $payload['name'],
                'source' => $payload['source'],
                'active_marker' => 1,
                'request_id' => $request->id,
            ]);
        } catch (QueryException $exception) {
            abort_if($this->isDuplicate($exception), 409, self::dateWords($payload['date']).' is already on the AMLA calendar as '.self::KIND_IN_A_SENTENCE[$payload['kind']].'.');

            throw $exception;
        }

        return ['calendar_entry' => $entry->id, 'date' => $payload['date'], 'kind' => $payload['kind']];
    }

    /** @return array<string, mixed> */
    private function applyCalendarRemove(AmlaChangeRequest $request): array
    {
        $entry = AmlaCalendarEntry::query()->lockForUpdate()->findOrFail((int) $request->payload['entry_id']);

        abort_if($entry->removed_at !== null, 409, 'This calendar day is already removed.');

        $entry->forceFill(['active_marker' => null, 'removal_request_id' => $request->id, 'removed_at' => now()])->save();

        return ['calendar_entry' => $entry->id, 'date' => $entry->date->toDateString(), 'kind' => $entry->kind];
    }

    /** @return array<string, mixed> */
    private function applyConfirmYear(AmlaChangeRequest $request): array
    {
        $year = (int) $request->payload['year'];

        try {
            $confirmed = AmlaCalendarYear::query()->create(['year' => $year, 'confirmed_at' => now(), 'request_id' => $request->id]);
        } catch (QueryException $exception) {
            abort_if($this->isDuplicate($exception), 409, "The {$year} AMLA calendar is already confirmed.");

            throw $exception;
        }

        return ['calendar_year' => $confirmed->year];
    }

    private function isDuplicate(QueryException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === 1062;
    }

    private static function dateWords(string $date): string
    {
        return CarbonImmutable::parse($date)->format('F j, Y');
    }
}
