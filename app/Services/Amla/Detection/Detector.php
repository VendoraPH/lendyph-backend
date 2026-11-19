<?php

namespace App\Services\Amla\Detection;

use App\Enums\PaymentMethod;
use App\Models\AmlaAlert;
use App\Models\AmlaAlertEvent;
use App\Models\AmlaCtrCandidate;
use App\Models\AmlaDetectionLock;
use App\Models\AmlaEvent;
use App\Models\AmlaEventScreening;
use App\Models\AmlaRuleVersion;
use App\Services\Amla\Events\PesoAmount;
use App\Services\Amla\Foundation\AmlaMonitoring;
use App\Services\Amla\Foundation\AmlaSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The AMLA detector (AMLA design §4.6–4.7), run every minute while AMLA
 * monitoring is on, after the harvester. It reads AMLA events (and, for a few
 * rules, the member's own record) and writes only AMLA tables:
 *
 * - **Screening.** Each event not yet screened (no AmlaEventScreening row),
 *   lowest id first, is checked once for a CTR candidate and against every
 *   active rule of the rule version in force when it was recorded. A finding
 *   opens an alert citing that version, or joins the open alert for the same
 *   rule, subject and window. Its screening row is written in the same
 *   transaction, so an event saved late below others is still screened, and
 *   an event is never screened twice.
 * - **CTR.** One customer event in a channel counted as cash, above the CTR
 *   threshold of the AMLA settings version in force when it was recorded.
 *   Exactly the threshold isn't one; same-day sums are structuring (rule A).
 *   An event already voided when screened still gets its candidate, marked
 *   reversed.
 * - **Reruns.** When the harvester marks an event voided or reversed, its
 *   CTR candidate records that, and each open alert it belongs to runs its
 *   rule again (the version it cites) on what still stands. An alert that no
 *   longer holds is cleared with a note; one that still holds stays open.
 *
 * Everything runs under a lock on one AmlaDetectionLock row, so two runs
 * never write at once, and reference numbers are taken under it. Nothing files, blocks,
 * notifies or freezes.
 */
final class Detector
{
    public const BATCH = 500;

    /** Batches screened per run, so one run stays short after a long pause. */
    public const BATCHES_PER_RUN = 20;

    /** @var array<int, AmlaRuleVersion> rule versions read this run */
    private array $versions = [];

    public function __construct(
        private readonly AmlaMonitoring $monitoring,
        private readonly DetectionRules $rules,
        private readonly AmlaSettings $settings,
    ) {}

    /**
     * @return array{screened: int, ctr_candidates: int, alerts_opened: int, alerts_joined: int, ctr_reversed: int, alerts_rerun: int, alerts_cleared: int}|null
     *                                                                                                                                                           null while monitoring is off
     */
    public function run(): ?array
    {
        if (! $this->monitoring->enabled()) {
            return null;
        }

        $counts = ['screened' => 0, 'ctr_candidates' => 0, 'alerts_opened' => 0, 'alerts_joined' => 0, 'ctr_reversed' => 0, 'alerts_rerun' => 0, 'alerts_cleared' => 0];

        for ($batch = 0; $batch < self::BATCHES_PER_RUN; $batch++) {
            if (DB::transaction(fn () => $this->screenBatch($counts)) === 0) {
                break;
            }
        }

        DB::transaction(fn () => $this->rerunReversals($counts));

        return $counts;
    }

    /** @param  array<string, int>  $counts */
    private function screenBatch(array &$counts): int
    {
        $this->lock();
        $events = AmlaEvent::query()
            ->whereNotExists(fn ($screened) => $screened->selectRaw('1')->from('amla_event_screenings')->whereColumn('amla_event_screenings.event_id', 'amla_events.id'))
            ->orderBy('id')->limit(self::BATCH)->get();

        foreach ($events as $event) {
            $moment = $event->recorded_at ?? $event->created_at ?? Carbon::now();
            $version = $this->ruleVersionAt($moment);
            $settings = $this->settings->at($moment);
            $counts['ctr_candidates'] += $this->ctr($event);

            foreach ($this->activeRules($version) as $code => [$rule, $params]) {
                foreach ($rule->evaluateAll($event, $this->context($event, $params)) as $finding) {
                    $counts[$this->record($code, $version->version, $version->definition[$code]['priority'], $event, $finding) ? 'alerts_opened' : 'alerts_joined']++;
                }
            }

            AmlaEventScreening::query()->create(['event_id' => $event->id, 'rule_version' => $version->version, 'settings_version' => $settings->version]);
            $counts['screened']++;
        }

        return $events->count();
    }

    /** @param  array<string, int>  $counts */
    private function rerunReversals(array &$counts): void
    {
        $this->lock();
        $now = Carbon::now();

        $candidates = AmlaCtrCandidate::query()->whereNull('event_reversed_at')
            ->whereHas('event', fn ($event) => $event->whereNotNull('reversed_at'))->with('event')->limit(self::BATCH)->get();

        foreach ($candidates as $candidate) {
            $candidate->markEventReversed($candidate->event->reversed_at ?? $now);
            $counts['ctr_reversed']++;
        }

        $links = AmlaAlertEvent::query()->whereNull('reversal_rerun_at')
            ->whereIn('event_id', AmlaEvent::query()->whereNotNull('reversed_at')->select('id'))
            ->orderBy('id')->limit(self::BATCH)->get();

        foreach ($links->groupBy('alert_id') as $alertId => $rows) {
            $alert = AmlaAlert::query()->find($alertId);

            if ($alert !== null && $alert->status === AmlaAlert::OPEN) {
                $counts['alerts_rerun']++;
                $reversed = AmlaEvent::query()->whereIn('id', $rows->pluck('event_id'))->pluck('reference')
                    ->map(fn ($reference) => $reference ?? 'a transaction with no reference')->unique()->implode(', ');

                if (! $this->stillHolds($alert)) {
                    $alert->clear("This alert no longer meets the rule after {$reversed} was voided or reversed. Rule version {$alert->rule_version} was run again on what still stands.", $now);
                    $counts['alerts_cleared']++;
                }
            }

            foreach ($rows as $row) {
                $row->markRerun($now);
            }
        }
    }

    /** Whether the alert's rule, at the version it cites, still finds the same window on what still stands. */
    private function stillHolds(AmlaAlert $alert): bool
    {
        $version = $this->rules->version($alert->rule_version);
        $rule = $this->rules->rule($alert->rule_code);
        $params = $version->definition[$alert->rule_code]['params'];
        $standing = $alert->events()->whereNull('amla_events.reversed_at')->orderByDesc('amla_events.id')->get();

        foreach ($standing as $event) {
            foreach ($rule->evaluateAll($event, $this->context($event, $params)) as $finding) {
                if (self::openKey($alert->rule_code, $finding) === $alert->open_key) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A CTR candidate for the event, when it is one. @return int 1 when one was added */
    private function ctr(AmlaEvent $event): int
    {
        if ($event->is_internal) {
            return 0;
        }

        $settings = $this->settings->at($event->recorded_at ?? $event->created_at ?? Carbon::now());
        $threshold = (string) $settings->values['ctr_threshold'];
        $channels = (array) $settings->values['ctr_cash_channels'];

        if (! in_array($event->channel, $channels, true) || PesoAmount::centavos((string) $event->amount) <= PesoAmount::centavos($threshold)
            || AmlaCtrCandidate::query()->where('event_id', $event->id)->exists()) {
            return 0;
        }

        AmlaCtrCandidate::query()->create([
            'reference' => self::reference('CTR', (int) AmlaCtrCandidate::query()->max('id') + 1),
            'event_id' => $event->id,
            'settings_version' => $settings->version,
            'threshold' => $threshold,
            'amount' => (string) $event->amount,
            'direction' => $event->direction,
            'channel' => $event->channel,
            'borrower_id' => $event->borrower_id,
            'gcash_non_member_id' => $event->gcash_non_member_id,
            'branch_id' => $event->branch_id,
            'occurred_on' => $event->occurred_on->toDateString(),
            'status' => AmlaCtrCandidate::DETECTED,
            'from_history' => $event->harvested_by === 'history',
            'event_reversed_at' => $event->reversed_at,
            'explanation' => sprintf('One %s transaction (%s) of %s on %s, above the CTR threshold of %s (AMLA settings version %d, to verify). Same-day sums are reviewed as structuring, never as a CTR.',
                strtolower((string) PaymentMethod::labelFor($event->channel)), $event->direction === 'in' ? 'money in' : 'money out',
                PesoAmount::words((string) $event->amount), $event->occurred_on->format('M j, Y'), PesoAmount::words($threshold), $settings->version),
        ]);

        return 1;
    }

    /** Open an alert for the finding, or join the open one. @return bool true when one was opened */
    private function record(string $code, int $version, string $priority, AmlaEvent $trigger, Finding $finding): bool
    {
        $key = self::openKey($code, $finding);
        $alert = AmlaAlert::query()->where('open_key', $key)->first();
        $opened = $alert === null;

        $alert ??= AmlaAlert::query()->create([
            'reference' => self::reference('ALR', (int) AmlaAlert::query()->max('id') + 1),
            'rule_code' => $code,
            'rule_version' => $version,
            'priority' => $priority,
            'status' => AmlaAlert::OPEN,
            'open_key' => $key,
            'borrower_id' => $trigger->borrower_id,
            'gcash_non_member_id' => $trigger->gcash_non_member_id,
            'branch_id' => $finding->headOffice ? null : $trigger->branch_id,
            'window_start' => $finding->windowStart,
            'window_end' => $finding->windowEnd,
            'reason' => $finding->reason,
            'indicators' => $finding->indicators,
            'from_history' => $trigger->harvested_by === 'history',
        ]);

        $linked = AmlaAlertEvent::query()->where('alert_id', $alert->id)->pluck('event_id')->map(fn ($id) => (int) $id)->all();

        foreach (array_diff($finding->eventIds, $linked) as $eventId) {
            AmlaAlertEvent::query()->create(['alert_id' => $alert->id, 'event_id' => $eventId]);
        }

        return $opened;
    }

    /**
     * The version's active rules whose data exists, with their parameters.
     *
     * @return array<string, array{0: DetectionRule, 1: array<string, mixed>}>
     */
    private function activeRules(AmlaRuleVersion $version): array
    {
        $active = [];

        foreach ($this->rules->all() as $code => $rule) {
            $entry = $version->definition[$code] ?? null;

            if ($entry !== null && $entry['active'] && $rule->canBeActive() && $rule->unavailable() === null) {
                $active[$code] = [$rule, (array) $entry['params']];
            }
        }

        return $active;
    }

    /** @param  array<string, mixed>  $params */
    private function context(AmlaEvent $event, array $params): DetectionContext
    {
        $settings = $this->settings->at($event->recorded_at ?? $event->created_at ?? Carbon::now());

        return new DetectionContext($params, (string) $settings->values['ctr_threshold'], (array) $settings->values['ctr_cash_channels'], $settings->version);
    }

    /**
     * Lock the detector row (written by the detection migration) as the
     * batch's first read, so every later read sees what a run before it
     * committed, never an older view.
     */
    private function lock(): void
    {
        AmlaDetectionLock::query()->where('name', 'detector')->lockForUpdate()->firstOrFail();
    }

    /** The rule version in force when the event was recorded, read once per version per run. */
    private function ruleVersionAt(CarbonInterface $moment): AmlaRuleVersion
    {
        $version = $this->rules->at($moment);

        return $this->versions[$version->version] ??= $version;
    }

    private static function openKey(string $code, Finding $finding): string
    {
        return mb_substr($code.'|'.$finding->key, 0, 191);
    }

    private static function reference(string $prefix, int $sequence): string
    {
        return $prefix.'-'.Carbon::now()->format('Ymd').'-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
