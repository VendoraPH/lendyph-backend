<?php

namespace App\Http\Resources\Amla;

use App\Enums\PaymentMethod;
use App\Models\AmlaAlert;
use App\Models\AmlaChangeRequest;
use App\Models\AmlaCtrCandidate;
use App\Models\AmlaCtrSetAside;
use App\Models\AmlaEvent;
use App\Models\AmlaReport;
use App\Models\AmlaRuleVersion;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\GCashNonMember;
use App\Models\User;
use App\Services\Amla\Detection\DetectionRules;
use App\Services\Amla\Detection\Finding;
use App\Services\Amla\Events\PesoAmount;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\BranchAccess;
use Illuminate\Support\Collection;

/**
 * AMLA alerts, CTR candidates and detection rules as the screens read them
 * (AMLA-B3; task §8 "Alert explainability", §12). Money in ₱ with two
 * decimals, everything in words, and "Data not available" wherever Lendy
 * holds no value, never a blank. A subject shows its name and code only.
 */
final class AmlaDetectionPresenter
{
    /** What a record with no branch reads: it belongs to the whole cooperative (multi-branch decision 6). */
    public const ORGANISATION_WIDE = 'Organisation-wide';

    public function __construct(private readonly DetectionRules $rules) {}

    /**
     * The alert's row; its case shows only to `amla:cases` holders with
     * head-office access (`$viewer`). Two figures, labelled apart:
     * `counted_amount` (and `counted_transactions_count`) is what the rule
     * counted when the alert opened, the figure its reason states (null on
     * alerts opened before AMLA-B8); `amount` (and `transactions_count`) is
     * every transaction linked since, later joins included, less any voided.
     *
     * @return array<string, mixed>
     */
    public function alertRow(AmlaAlert $alert, ?User $viewer = null): array
    {
        $standing = $alert->events->whereNull('reversed_at');

        return [
            'id' => (int) $alert->id,
            'reference' => $alert->reference,
            'rule_code' => $alert->rule_code,
            'rule' => $this->rules->rule($alert->rule_code)->words(),
            'rule_version' => (int) $alert->rule_version,
            'priority' => $alert->priority,
            'priority_words' => AmlaAlert::PRIORITY_WORDS[$alert->priority] ?? ucfirst($alert->priority),
            'status' => $alert->status,
            'status_words' => AmlaAlert::STATUS_WORDS[$alert->status] ?? ucfirst($alert->status),
            'subject' => self::subject($alert->borrower, $alert->walkIn),
            'branch' => self::branch($alert->branch, $alert->branch_id),
            'window_start' => $alert->window_start->toDateString(),
            'window_end' => $alert->window_end->toDateString(),
            'amount' => self::pesos($standing),
            'transactions_count' => $alert->events->count(),
            'counted_amount' => $alert->counted_amount === null ? null : PesoAmount::words((string) $alert->counted_amount),
            'counted_transactions_count' => $alert->counted_transactions,
            'from_history' => (bool) $alert->from_history,
            'assigned_to' => AmlaPresenter::user($alert->assignee),
            'case' => $alert->amlaCase === null || ! self::seesCases($viewer) ? null : ['id' => (int) $alert->amlaCase->id, 'reference' => $alert->amlaCase->reference],
            'closed_at' => $alert->closed_at?->toIso8601String(),
            'detected_at' => $alert->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function alertDetail(AmlaAlert $alert, ?User $viewer = null): array
    {
        return [
            ...$this->alertRow($alert, $viewer),
            'reason' => $alert->reason,
            'indicators' => $alert->indicators,
            'cleared_at' => $alert->cleared_at?->toIso8601String(),
            'cleared_note' => $alert->cleared_note,
            'transactions' => $alert->events->sortBy('id')->map(fn (AmlaEvent $event) => self::transaction($event))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function ctrRow(AmlaCtrCandidate $candidate): array
    {
        return [
            'id' => (int) $candidate->id,
            'reference' => $candidate->reference,
            'status' => $candidate->status,
            'status_words' => AmlaCtrCandidate::STATUS_WORDS[$candidate->status] ?? ucfirst($candidate->status),
            'amount' => PesoAmount::words((string) $candidate->amount),
            'threshold' => PesoAmount::words((string) $candidate->threshold),
            'settings_version' => (int) $candidate->settings_version,
            'direction_words' => $candidate->direction === 'in' ? 'Money in' : 'Money out',
            'channel_words' => self::channel($candidate->channel),
            'subject' => self::subject($candidate->borrower, $candidate->walkIn),
            'branch' => self::branch($candidate->branch, $candidate->branch_id),
            'occurred_on' => $candidate->occurred_on->toDateString(),
            'from_history' => (bool) $candidate->from_history,
            'event_reversed_at' => $candidate->event_reversed_at?->toIso8601String(),
            'detected_at' => $candidate->created_at?->toIso8601String(),
        ];
    }

    /**
     * A candidate's page, for an `amla:ctr` holder who sees it: its CTR's id
     * when one is prepared (a returned one doesn't count), and a waiting
     * set-aside ask with its reason and whether `$viewer` asked
     * (`requested_by_me`), so the screen can apply the rule that the asker
     * never decides. The asker is named only to `amla:approve` holders.
     *
     * @return array<string, mixed>
     */
    public function ctrDetail(AmlaCtrCandidate $candidate, User $viewer): array
    {
        $reportId = AmlaReport::query()->where('ctr_candidate_id', $candidate->id)->where('status', '!=', AmlaReport::RETURNED)->max('id');
        $ask = $candidate->status === AmlaCtrCandidate::SET_ASIDE_PENDING
            ? AmlaCtrSetAside::query()->with('requester')->where('pending_key', $candidate->id)->first()
            : null;

        return [
            ...$this->ctrRow($candidate),
            'explanation' => $candidate->explanation,
            'transaction' => $candidate->event === null ? null : self::transaction($candidate->event),
            'ctr_report_id' => $reportId === null ? null : (int) $reportId,
            'set_aside' => $ask === null ? null : [
                'id' => (int) $ask->id,
                'reason' => $ask->reason,
                'requested_by_me' => (int) $ask->requested_by === (int) $viewer->id,
                'requested_by' => $viewer->can(AmlaAccess::APPROVE) ? AmlaPresenter::user($ask->requester) : null,
                'requested_at' => $ask->created_at?->toIso8601String(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function version(AmlaRuleVersion $version): array
    {
        $rules = [];

        foreach ($this->rules->all() as $code => $rule) {
            $entry = $version->definition[$code];
            $rules[] = [
                'code' => $code,
                'rule' => $rule->words(),
                'description' => $rule->description(),
                'active' => (bool) $entry['active'],
                'priority' => $entry['priority'],
                'priority_words' => AmlaAlert::PRIORITY_WORDS[$entry['priority']] ?? ucfirst($entry['priority']),
                'params' => $entry['params'],
                'parameters' => array_map(fn (string $name, string $kind) => [
                    'name' => $name,
                    'label' => DetectionRules::PARAMETER_LABELS[$name] ?? ucfirst(str_replace('_', ' ', $name)),
                    'kind' => $kind,
                    'value' => $entry['params'][$name] ?? null,
                ], array_keys($rule->parameters()), array_values($rule->parameters())),
                'availability' => $rule->unavailable() ?? 'Available',
            ];
        }

        $base = $version->request_id === null ? null : AmlaChangeRequest::query()->find($version->request_id)?->payload;

        return [
            'version' => (int) $version->version,
            'base_version' => is_array($base) && isset($base['base_version']) ? (int) $base['base_version'] : null,
            'pending_change_request_id' => self::pendingChange(),
            'to_verify' => (bool) $version->to_verify,
            'effective_at' => $version->effective_at?->toIso8601String(),
            'note' => DetectionRules::NOTE,
            'rules' => $rules,
        ];
    }

    /** @return array<string, mixed> */
    private static function transaction(AmlaEvent $event): array
    {
        $event->loadMissing('branch');

        return [
            'id' => (int) $event->id,
            'kind' => $event->kind,
            'kind_words' => AmlaEvent::kindWords($event->kind),
            'direction_words' => $event->direction === 'in' ? 'Money in' : 'Money out',
            'internal' => (bool) $event->is_internal,
            'amount' => PesoAmount::words((string) $event->amount),
            'occurred_on' => $event->occurred_on->toDateString(),
            'channel' => self::channel($event->channel),
            'reference' => $event->reference ?? Finding::NOT_AVAILABLE,
            'branch' => $event->branch?->name ?? Finding::NOT_AVAILABLE,
            'reversed' => $event->reversed_at !== null,
            'reversed_at' => $event->reversed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private static function subject(?Borrower $member, ?GCashNonMember $walkIn): array
    {
        if ($member !== null) {
            return ['type' => 'member', 'id' => (int) $member->id, 'name' => (string) $member->full_name, 'code' => $member->borrower_code ?? Finding::NOT_AVAILABLE, 'account' => Finding::NOT_AVAILABLE];
        }

        if ($walkIn !== null) {
            return ['type' => 'walk_in', 'id' => (int) $walkIn->id, 'name' => (string) $walkIn->full_name, 'code' => Finding::NOT_AVAILABLE, 'account' => Finding::NOT_AVAILABLE];
        }

        return ['type' => null, 'id' => null, 'name' => Finding::NOT_AVAILABLE, 'code' => Finding::NOT_AVAILABLE, 'account' => Finding::NOT_AVAILABLE];
    }

    /** @return array{id: int|null, name: string} */
    private static function branch(?Branch $branch, ?int $branchId): array
    {
        return ['id' => $branchId, 'name' => $branch?->name ?? ($branchId === null ? self::ORGANISATION_WIDE : Finding::NOT_AVAILABLE)];
    }

    private static function channel(?string $channel): string
    {
        return PaymentMethod::labelFor($channel) ?? Finding::NOT_AVAILABLE;
    }

    /** @param  Collection<int, AmlaEvent>  $events */
    private static function pesos(Collection $events): string
    {
        return PesoAmount::words(PesoAmount::fromCentavos($events->sum(fn (AmlaEvent $event) => PesoAmount::centavos((string) $event->amount))));
    }

    /** Whether the viewer may see which case an alert went to: cases are head office's. */
    public static function seesCases(?User $viewer): bool
    {
        return $viewer !== null && $viewer->can(AmlaAccess::CASES) && $viewer->can(BranchAccess::VIEW_ALL);
    }

    /** The detection rules change waiting for approval, if any (one waits at a time). */
    private static function pendingChange(): ?int
    {
        $id = AmlaChangeRequest::query()->where('pending_key', AmlaChangeRequest::DETECTION_RULES)->value('id');

        return $id === null ? null : (int) $id;
    }
}
