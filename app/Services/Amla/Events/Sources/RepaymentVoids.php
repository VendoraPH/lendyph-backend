<?php

namespace App\Services\Amla\Events\Sources;

use App\Models\AmlaEvent;
use App\Services\Amla\Events\BuiltRow;
use App\Services\Amla\Events\EventSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Voided payments (AMLA design §4.3): a void adds no event. It marks its
 * receipt's event reversed, once, recorded at `voided_at`, so the rules run
 * again on it (AMLA-B3). Only voids of receipts that have an event are
 * monitored: a receipt from before go-live has nothing to mark.
 */
final class RepaymentVoids extends EventSource
{
    public function name(): string
    {
        return 'repayment_voids';
    }

    public function words(): string
    {
        return 'Payment voids';
    }

    public function eventSource(): string
    {
        return 'repayment';
    }

    public function recordedColumn(): string
    {
        return 'repayments.voided_at';
    }

    public function idColumn(): string
    {
        return 'repayments.id';
    }

    public function rows(): Builder
    {
        return DB::table('repayments')
            ->where('repayments.status', 'voided')
            ->whereNotNull('repayments.voided_at')
            ->whereExists(fn (Builder $event) => $event->selectRaw('1')->from('amla_events')
                ->where('amla_events.source', 'repayment')
                ->whereColumn('amla_events.source_id', 'repayments.id'))
            ->select(['repayments.id', 'repayments.voided_at as recorded']);
    }

    public function build(stdClass $row, string $harvestedBy): BuiltRow
    {
        return new BuiltRow([], [[
            'source' => 'repayment', 'source_id' => (int) $row->id, 'by' => 'repayment_void', 'by_id' => (int) $row->id, 'at' => (string) $row->recorded,
        ]]);
    }

    public function coveredIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return AmlaEvent::query()->where('source', 'repayment')->whereIn('source_id', $ids)->whereNotNull('reversed_at')
            ->distinct()->pluck('source_id')->map(fn ($id) => (int) $id)->all();
    }
}
