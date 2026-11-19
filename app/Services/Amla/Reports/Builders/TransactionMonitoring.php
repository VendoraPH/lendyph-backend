<?php

namespace App\Services\Amla\Reports\Builders;

use App\Enums\PaymentMethod;
use App\Models\AmlaEvent;
use App\Models\Branch;
use App\Models\User;
use App\Services\Amla\Detection\DetectionScope;
use App\Services\Amla\Events\PesoAmount;
use App\Services\Amla\Foundation\AmlaAccess;
use App\Services\Amla\Reports\ComplianceReport;
use App\Services\Amla\Reports\ReportFilters;
use App\Services\Amla\Reports\ReportNames;
use Illuminate\Support\Facades\DB;

/**
 * Report 3, Transaction monitoring: the period's customer transactions
 * (transaction date), by branch and channel: how many, money in, out and net,
 * how many were screened, are still unscreened or were flagged by an alert,
 * the alert categories and the rule versions used. Internal movements are
 * left out, as they are never new funds.
 */
final class TransactionMonitoring extends ComplianceReport
{
    public function __construct(private readonly DetectionScope $scope) {}

    public function key(): string
    {
        return 'transaction_monitoring';
    }

    public function title(): string
    {
        return 'Transaction Monitoring';
    }

    public function description(): string
    {
        return 'Customer transactions by branch and channel: volume, screened, unscreened and flagged, with alert categories and rule versions.';
    }

    public function permissions(): array
    {
        return [AmlaAccess::ALERTS];
    }

    public function columns(): array
    {
        return [
            ['key' => 'branch', 'label' => 'Branch', 'format' => 'text'],
            ['key' => 'channel', 'label' => 'Channel', 'format' => 'text'],
            ['key' => 'transactions', 'label' => 'Transactions', 'format' => 'number'],
            ['key' => 'money_in', 'label' => 'Money in', 'format' => 'money'],
            ['key' => 'money_out', 'label' => 'Money out', 'format' => 'money'],
            ['key' => 'net', 'label' => 'Net', 'format' => 'money'],
            ['key' => 'screened', 'label' => 'Screened', 'format' => 'number'],
            ['key' => 'unscreened', 'label' => 'Unscreened', 'format' => 'number'],
            ['key' => 'flagged', 'label' => 'Flagged', 'format' => 'number'],
            ['key' => 'categories', 'label' => 'Alert categories', 'format' => 'text'],
            ['key' => 'rule_versions', 'label' => 'Rule versions', 'format' => 'text'],
        ];
    }

    public function rows(User $viewer, ReportFilters $filters): array
    {
        $events = $this->scope->constrain(AmlaEvent::query(), $viewer)->where('is_internal', false)
            ->whereBetween('occurred_on', [$filters->from, $filters->to])
            ->when($filters->branchId !== null, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->get(['id', 'branch_id', 'channel', 'direction', 'amount']);
        $ids = $events->pluck('id')->all();
        $screened = DB::table('amla_event_screenings')->whereIn('event_id', $ids)->pluck('rule_version', 'event_id');
        $flags = DB::table('amla_alert_events')->join('amla_alerts', 'amla_alerts.id', '=', 'amla_alert_events.alert_id')
            ->whereIn('amla_alert_events.event_id', $ids)->get(['amla_alert_events.event_id', 'amla_alerts.rule_code'])->groupBy('event_id');
        $branches = Branch::query()->whereIn('id', $events->pluck('branch_id')->filter()->unique())->pluck('name', 'id');
        $rows = [];

        foreach ($events->groupBy(fn (AmlaEvent $event) => ($event->branch_id ?? 0).'|'.$event->channel) as $group) {
            $first = $group->first();
            $in = $group->where('direction', 'in')->sum(fn (AmlaEvent $event) => PesoAmount::centavos((string) $event->amount));
            $out = $group->where('direction', 'out')->sum(fn (AmlaEvent $event) => PesoAmount::centavos((string) $event->amount));
            $groupScreened = $group->filter(fn (AmlaEvent $event) => $screened->has($event->id));
            $rows[] = [
                'branch' => $first->branch_id === null ? ReportNames::ORGANISATION_WIDE : ($branches[$first->branch_id] ?? null),
                'channel' => PaymentMethod::labelFor($first->channel),
                'transactions' => $group->count(),
                'money_in' => PesoAmount::fromCentavos($in),
                'money_out' => PesoAmount::fromCentavos($out),
                'net' => PesoAmount::fromCentavos($in - $out),
                'screened' => $groupScreened->count(),
                'unscreened' => $group->count() - $groupScreened->count(),
                'flagged' => $group->filter(fn (AmlaEvent $event) => $flags->has($event->id))->count(),
                'categories' => $group->flatMap(fn (AmlaEvent $event) => $flags->get($event->id, collect())->pluck('rule_code'))->unique()->sort()->implode(', ') ?: null,
                'rule_versions' => $groupScreened->map(fn (AmlaEvent $event) => (int) $screened[$event->id])->unique()->sort()->implode(', ') ?: null,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['branch'], $a['channel']] <=> [$b['branch'], $b['channel']]);

        return $rows;
    }

    public function totals(array $rows): array
    {
        return self::summed($rows, ['transactions', 'screened', 'unscreened', 'flagged'], ['money_in', 'money_out', 'net'], 'branch');
    }
}
