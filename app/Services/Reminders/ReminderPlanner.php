<?php

namespace App\Services\Reminders;

use App\Models\AmortizationSchedule;
use App\Models\Loan;
use App\Models\ReminderEvent;
use App\Models\ReminderQueueItem;
use App\Models\ReminderRule;
use App\Models\ReminderSetting;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `reminders:plan`: works out which reminders fire from today to
 * `reminders.plan_days_ahead` days ahead and plans each once.
 *
 * 1. Sweep: cancel planned reminders no longer needed (ReminderSweeper).
 * 2. While reminders are switched on, plan one row per open period of a
 *    collectible loan, active rule covering the loan, and channel switched on,
 *    whose fire date (FireDate) is in the window. The unique key (loan,
 *    period number, due date, rule, channel) makes a second run plan nothing
 *    new. A row cancelled before any attempt comes back if its period is open
 *    again (a voided payment, a due date moved back).
 * 3. One reminder per loan, per channel, per day: when two land on the same
 *    day, the later rule (the one furthest into the period's life) wins and
 *    the rest are cancelled as superseded.
 *
 * Planning only plans: whether a reminder goes, and what it says, is decided
 * at send time from the live loan.
 */
final class ReminderPlanner
{
    private const REVIVABLE = ['paid', 'schedule_changed', 'loan_closed', 'nothing_due'];

    public function __construct(
        private readonly ReminderSweeper $sweeper,
        private readonly ReminderEligibility $eligibility,
    ) {}

    /**
     * @return array{cancelled: int, planned: int, revived: int, superseded: int, enabled: bool}
     */
    public function plan(?CarbonInterface $now = null): array
    {
        $now = Carbon::instance($now ?? now());
        $result = ['cancelled' => $this->sweeper->sweep(), 'planned' => 0, 'revived' => 0, 'superseded' => 0, 'enabled' => false];
        $settings = ReminderSetting::current();

        if (! $settings->enabled) {
            return $result;
        }

        $result['enabled'] = true;
        $rules = ReminderRule::query()->active()->orderBy('id')->get();

        if ($rules->isEmpty()) {
            return $result;
        }

        $today = ReminderClock::today($now);
        $horizon = $today->copy()->addDays(max(0, (int) config('reminders.plan_days_ahead', 7)));
        $hours = ContactHours::from($settings);
        $maxBefore = (int) $rules->where('trigger', 'before_due')->max('days');
        $maxGrace = (int) Loan::query()->whereIn('status', Loan::COLLECTIBLE_STATUSES)->max('grace_period_days');
        $lookBack = max((int) $rules->where('trigger', 'after_due')->max('days'), $maxGrace + 1);
        $dueFrom = $today->copy()->subDays($lookBack)->toDateString();
        $dueTo = $horizon->copy()->addDays($maxBefore)->toDateString();

        $openInWindow = fn ($schedules) => $schedules
            ->whereIn('status', AmortizationSchedule::UNPAID_STATUSES)
            ->whereBetween('due_date', [$dueFrom, $dueTo]);

        Loan::query()
            ->whereIn('status', Loan::COLLECTIBLE_STATUSES)
            ->whereHas('amortizationSchedules', $openInWindow)
            ->with(['borrower:id,status,branch_id', 'amortizationSchedules'])
            ->chunkById(200, function (Collection $loans) use ($rules, $settings, $hours, $today, $horizon, $now, $dueFrom, $dueTo, &$result): void {
                foreach ($loans as $loan) {
                    [$planned, $revived] = $this->planLoan($loan, $rules, $settings, $hours, $today, $horizon, $now, $dueFrom, $dueTo);
                    $result['planned'] += $planned;
                    $result['revived'] += $revived;
                }
            });

        $result['superseded'] = $this->supersede($today, $horizon);

        return $result;
    }

    /**
     * @param  Collection<int, ReminderRule>  $rules
     * @return array{0: int, 1: int}
     */
    private function planLoan(Loan $loan, Collection $rules, ReminderSetting $settings, ContactHours $hours, Carbon $today, Carbon $horizon, Carbon $now, string $dueFrom, string $dueTo): array
    {
        $existing = ReminderQueueItem::query()->where('loan_id', $loan->id)->get()->keyBy(fn (ReminderQueueItem $row) => self::key(
            (int) $row->period_number, $row->due_date->toDateString(), $row->rule_id === null ? null : (int) $row->rule_id, $row->channel,
        ));
        $periods = $loan->amortizationSchedules->filter(fn (AmortizationSchedule $s) => in_array($s->status, AmortizationSchedule::UNPAID_STATUSES, true)
            && $s->due_date->toDateString() >= $dueFrom && $s->due_date->toDateString() <= $dueTo);
        $planned = 0;
        $revived = 0;
        $amounts = [];

        foreach ($periods as $period) {
            foreach ($rules as $rule) {
                if (! $rule->appliesTo($loan->branch_id === null ? null : (int) $loan->branch_id, (int) $loan->loan_product_id, $loan->borrower?->status)) {
                    continue;
                }

                $fireDate = FireDate::for($rule, $period->due_date, $loan->grace_period_days);

                if ($fireDate->lt($today) || $fireDate->gt($horizon)) {
                    continue;
                }

                $amounts[$period->id] ??= $this->eligibility->amountDue($loan, $period, $now);

                if ($amounts[$period->id] <= 0) {
                    continue;
                }

                foreach (array_values(array_intersect(ReminderRule::CHANNELS, (array) $rule->channels)) as $channel) {
                    if (! $settings->channelEnabled($channel)) {
                        continue;
                    }

                    $key = self::key((int) $period->period_number, $period->due_date->toDateString(), (int) $rule->id, $channel);
                    $row = $existing->get($key);

                    if ($row === null) {
                        $planned += $this->insert($loan, $period, $rule, $channel, $fireDate, $hours, $amounts[$period->id]) ? 1 : 0;
                    } elseif ($this->revive($row, $fireDate, $hours, $rule, $amounts[$period->id])) {
                        $revived++;
                    } elseif (in_array($row->status, ReminderQueueItem::OPEN_STATUSES, true) && (float) $row->amount_due !== $amounts[$period->id]) {
                        $row->forceFill(['amount_due' => $amounts[$period->id]])->save();
                    }
                }
            }
        }

        return [$planned, $revived];
    }

    private function insert(Loan $loan, AmortizationSchedule $period, ReminderRule $rule, string $channel, Carbon $fireDate, ContactHours $hours, float $amount): bool
    {
        try {
            return DB::transaction(function () use ($loan, $period, $rule, $channel, $fireDate, $hours, $amount): bool {
                $item = ReminderQueueItem::query()->create([
                    'loan_id' => $loan->id,
                    'borrower_id' => $loan->borrower_id,
                    'branch_id' => $loan->branch_id,
                    'amortization_schedule_id' => $period->id,
                    'period_number' => $period->period_number,
                    'due_date' => $period->due_date->toDateString(),
                    'rule_id' => $rule->id,
                    'channel' => $channel,
                    'template_type' => $rule->template_type,
                    'amount_due' => $amount,
                    'fire_date' => $fireDate->toDateString(),
                    'scheduled_at' => ReminderClock::toApp($hours->scheduleOn($fireDate, $rule->send_time)),
                    'status' => 'scheduled',
                    'source' => 'system',
                ]);
                ReminderEvent::forItem('scheduled', $item, $rule->name.' ('.ReminderEligibility::channelLabel($channel).')');

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private function revive(ReminderQueueItem $row, Carbon $fireDate, ContactHours $hours, ReminderRule $rule, float $amount): bool
    {
        if ($row->status !== 'cancelled' || $row->attempts > 0 || ! in_array($row->reason_code, self::REVIVABLE, true)) {
            return false;
        }

        $row->forceFill([
            'status' => 'scheduled',
            'reason_code' => null,
            'reason' => null,
            'amount_due' => $amount,
            'fire_date' => $fireDate->toDateString(),
            'scheduled_at' => ReminderClock::toApp($hours->scheduleOn($fireDate, $rule->send_time)),
        ])->save();
        ReminderEvent::forItem('scheduled', $row, $rule->name.' ('.ReminderEligibility::channelLabel($row->channel).'), planned again');

        return true;
    }

    /**
     * Cancels all but one open system reminder per loan, channel and day in
     * the window: the later rule wins (furthest into the period's life), then
     * the older period, then the first planned.
     */
    private function supersede(Carbon $today, Carbon $horizon): int
    {
        $groups = ReminderQueueItem::query()
            ->select(['loan_id', 'channel', 'fire_date'])
            ->whereIn('status', ReminderQueueItem::OPEN_STATUSES)
            ->where('source', 'system')
            ->whereBetween('fire_date', [$today->toDateString(), $horizon->toDateString()])
            ->groupBy('loan_id', 'channel', 'fire_date')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $cancelled = 0;

        foreach ($groups as $group) {
            $rows = ReminderQueueItem::query()
                ->with('rule')
                ->where('loan_id', $group->loan_id)
                ->where('channel', $group->channel)
                ->whereDate('fire_date', $group->fire_date->toDateString())
                ->whereIn('status', ReminderQueueItem::OPEN_STATUSES)
                ->where('source', 'system')
                ->get()
                ->sort(fn (ReminderQueueItem $a, ReminderQueueItem $b) => [-(int) $a->rule?->offsetDays(), $a->period_number, $a->id]
                    <=> [-(int) $b->rule?->offsetDays(), $b->period_number, $b->id])
                ->values();

            foreach ($rows->slice(1) as $row) {
                $reason = 'A later reminder covers this loan the same day';
                $row->forceFill(['status' => 'cancelled', 'reason_code' => 'superseded', 'reason' => $reason])->save();
                ReminderEvent::forItem('cancelled', $row, $reason);
                $cancelled++;
            }
        }

        return $cancelled;
    }

    private static function key(int $period, string $dueDate, ?int $ruleId, string $channel): string
    {
        return implode('|', [$period, $dueDate, $ruleId ?? 'manual', $channel]);
    }
}
