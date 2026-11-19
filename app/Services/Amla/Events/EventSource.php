<?php

namespace App\Services\Amla\Events;

use App\Models\AmlaEvent;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Query\Builder;
use stdClass;

/**
 * One read-only adapter between a money table and AMLA events (AMLA design
 * §4.2–4.4). It names its rows and their recording time, and turns one row
 * into its events. It never writes, and never works an amount out: each
 * amount is a column of the row, as stored.
 */
abstract class EventSource
{
    /** The cursor, failure and coverage key ("gcash"). */
    abstract public function name(): string;

    /** The source in words, for commands and reports ("GCash"). */
    abstract public function words(): string;

    /** The `amla_events.source` this adapter writes ("gcash"). */
    abstract public function eventSource(): string;

    /** The row's recording time, qualified (a column or an expression): what the cursor reads. */
    abstract public function recordedColumn(): string|Expression;

    /** The row's id, qualified. */
    abstract public function idColumn(): string;

    /**
     * Every monitored row, with what build() reads selected, including the
     * row's id as `id` and its recording time as `recorded`.
     */
    abstract public function rows(): Builder;

    /** Turn one row into its events, or refuse in words (EventCannotBeBuilt). */
    abstract public function build(stdClass $row, string $harvestedBy): BuiltRow;

    /** Rows recorded but not yet a money movement (a pending GCash Cash In), for coverage; null when none can be. */
    public function pendingRows(): ?Builder
    {
        return null;
    }

    /**
     * Of these row ids, those whose events exist: what coverage counts as
     * harvested.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function coveredIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return AmlaEvent::query()->where('source', $this->eventSource())->whereIn('source_id', $ids)
            ->when($this->coverageKinds() !== null, fn ($events) => $events->whereIn('kind', $this->coverageKinds()))
            ->distinct()->pluck('source_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The kinds whose event counts a row as harvested, when the source shares
     * its `amla_events.source` with another adapter; null for any kind.
     *
     * @return list<string>|null
     */
    protected function coverageKinds(): ?array
    {
        return null;
    }

    /**
     * An event's columns, with the defaults every event shares.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function event(stdClass $row, string $kind, string $harvestedBy, array $values): array
    {
        return [
            'source' => $this->eventSource(),
            'source_id' => (int) $row->id,
            'kind' => $kind,
            'harvested_by' => $harvestedBy,
            'recorded_at' => $row->recorded,
            'linked_source' => null,
            'linked_source_id' => null,
            'reversed_at' => null,
            'reversal_source' => null,
            'reversal_source_id' => null,
            'channel_reference' => null,
            ...$values,
        ];
    }

    /**
     * A positive amount as stored, or a refusal naming the row.
     *
     * @throws EventCannotBeBuilt
     */
    protected function positive(?string $amount, string $what): string
    {
        if ($amount === null || PesoAmount::centavos($amount) <= 0) {
            throw new EventCannotBeBuilt("{$what} has no amount above ₱0.00.");
        }

        return PesoAmount::normal($amount);
    }

    protected static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
