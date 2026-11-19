<?php

namespace App\Http\Resources\Amla;

use App\Enums\PaymentMethod;
use App\Models\AmlaAlert;
use App\Models\AmlaCtrCandidate;
use App\Models\AmlaEvent;
use App\Models\AmlaRuleVersion;
use App\Models\Borrower;
use App\Models\Branch;
use App\Models\GCashNonMember;
use App\Services\Amla\Detection\DetectionRules;
use App\Services\Amla\Detection\Finding;
use App\Services\Amla\Events\PesoAmount;
use Illuminate\Support\Collection;

/**
 * AMLA alerts, CTR candidates and detection rules as the screens read them
 * (AMLA-B3; task §8 "Alert explainability", §12). Money in ₱ with two
 * decimals, everything in words, and "Data not available" wherever Lendy
 * holds no value, never a blank. A subject shows its name and code only.
 */
final class AmlaDetectionPresenter
{
    public function __construct(private readonly DetectionRules $rules) {}

    /** @return array<string, mixed> */
    public function alertRow(AmlaAlert $alert): array
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
            'from_history' => (bool) $alert->from_history,
            'detected_at' => $alert->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function alertDetail(AmlaAlert $alert): array
    {
        return [
            ...$this->alertRow($alert),
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

    /** @return array<string, mixed> */
    public function ctrDetail(AmlaCtrCandidate $candidate): array
    {
        return [
            ...$this->ctrRow($candidate),
            'explanation' => $candidate->explanation,
            'transaction' => $candidate->event === null ? null : self::transaction($candidate->event),
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
                'availability' => $rule->unavailable() ?? 'Available',
            ];
        }

        return [
            'version' => (int) $version->version,
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
        return ['id' => $branchId, 'name' => $branch?->name ?? ($branchId === null ? 'Head office' : Finding::NOT_AVAILABLE)];
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
}
