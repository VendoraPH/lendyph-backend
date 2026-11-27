<?php

namespace App\Services\Amla\Detection;

use App\Enums\PaymentMethod;
use App\Models\AmlaEvent;
use App\Services\Amla\Events\PesoAmount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One AMLA detection rule (task §8, categories A–L). It reads only AMLA
 * events (and, for a few, the member's own record), counts only customer
 * movements that weren't voided or reversed, and turns one trigger event
 * into a Finding or nothing. Every default is a starting point, not a legal
 * threshold.
 */
abstract class DetectionRule
{
    /** A–L. */
    abstract public function code(): string;

    /** The category in words ("Transaction structuring"). */
    abstract public function words(): string;

    /** What the rule looks for, in words, for the rules screen. */
    abstract public function description(): string;

    /**
     * Each parameter's kind: count, days, multiplier, percent, peso or channels.
     *
     * @return array<string, string>
     */
    abstract public function parameters(): array;

    abstract public function evaluate(AmlaEvent $trigger, DetectionContext $context): ?Finding;

    /**
     * Every finding for the trigger. One for most rules; a rule anchored on
     * an earlier transaction (E's receipt, I's release) returns one per
     * anchor that still matches, so a rerun checks an alert against its own.
     *
     * @return list<Finding>
     */
    public function evaluateAll(AmlaEvent $trigger, DetectionContext $context): array
    {
        $finding = $this->evaluate($trigger, $context);

        return $finding === null ? [] : [$finding];
    }

    /** Null while the rule's data exists; otherwise why it can't run, in words. */
    public function unavailable(): ?string
    {
        return null;
    }

    /** Whether the rule may ever be switched on (false for one whose data Lendy doesn't hold). */
    public function canBeActive(): bool
    {
        return true;
    }

    /** The subject's customer events that still stand: not internal, not voided or reversed. */
    protected function subjectEvents(AmlaEvent $trigger): Builder
    {
        return AmlaEvent::query()
            ->where('is_internal', false)
            ->whereNull('reversed_at')
            ->when(
                $trigger->borrower_id !== null,
                fn (Builder $events) => $events->where('borrower_id', $trigger->borrower_id),
                fn (Builder $events) => $events->where('gcash_non_member_id', $trigger->gcash_non_member_id),
            );
    }

    /** Whether the trigger is a customer movement of a known subject that still stands. */
    protected function counts(AmlaEvent $trigger): bool
    {
        return ! $trigger->is_internal
            && $trigger->reversed_at === null
            && ($trigger->borrower_id !== null || $trigger->gcash_non_member_id !== null);
    }

    protected function subjectKey(AmlaEvent $trigger): string
    {
        return $trigger->borrower_id !== null ? "member:{$trigger->borrower_id}" : "walk_in:{$trigger->gcash_non_member_id}";
    }

    protected static function day(AmlaEvent $event): CarbonImmutable
    {
        return CarbonImmutable::parse($event->occurred_on->toDateString());
    }

    /** Monday of the trigger's week, Manila calendar. */
    protected static function weekStart(AmlaEvent $event): CarbonImmutable
    {
        return self::day($event)->startOfWeek(CarbonImmutable::MONDAY);
    }

    /** @param  Collection<int, AmlaEvent>  $events */
    protected static function total(Collection $events): int
    {
        return $events->sum(fn (AmlaEvent $event) => PesoAmount::centavos((string) $event->amount));
    }

    protected static function cents(AmlaEvent $event): int
    {
        return PesoAmount::centavos((string) $event->amount);
    }

    protected static function peso(int $centavos): string
    {
        return PesoAmount::words(PesoAmount::fromCentavos($centavos));
    }

    /** @param  list<string>  $channels */
    protected static function channelWords(array $channels): string
    {
        return implode(', ', array_map(fn (string $channel) => (string) PaymentMethod::labelFor($channel), $channels));
    }

    protected static function dateWords(CarbonImmutable $day): string
    {
        return $day->format('M j, Y');
    }

    /** @param  Collection<int, AmlaEvent>  $events @return list<int> */
    protected static function ids(Collection $events): array
    {
        return $events->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** @return array{label: string, value: string} */
    protected static function indicator(string $label, ?string $value): array
    {
        return ['label' => $label, 'value' => $value === null || $value === '' ? Finding::NOT_AVAILABLE : $value];
    }
}
