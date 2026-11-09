<?php

namespace App\Services\Reminders\Api;

use App\Http\Resources\Reminders\ReminderPauseResource;
use App\Http\Resources\Reminders\ReminderQueueItemResource;
use App\Models\ReminderMessage;
use App\Models\ReminderPause;
use App\Models\ReminderQueueItem;
use App\Models\ReminderSetting;
use App\Models\User;
use App\Services\Reminders\ReminderClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The reminders dashboard for one Manila day (frontend `ReminderDashboard`),
 * over the caller's visible loans and the list filters.
 *
 * - `today`, `pending` and `failed` count that day's queue rows;
 *   `upcoming_due` and `overdue` count the loans among them whose period is
 *   due that day or later, or earlier.
 * - "Sent" counts only messages that reached a real provider; a message that
 *   was only logged (its own `test_mode`) counts as "logged" instead. Today
 *   every message is logged.
 * - `daily` is the 7 days ending on the date, oldest first.
 * - `sms` totals the date's month; the credit balance is not known (null).
 * - `test_mode` and `enabled` let a view-only role see the test-mode banner.
 */
final class ReminderDashboard
{
    private const HANDED_OVER = ['sent', 'delivered', 'opened'];

    private const FAILED = ['failed', 'rejected', 'bounced'];

    public function __construct(
        private readonly ReminderScope $scope,
        private readonly DeploymentMode $mode,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(User $user, array $filters, Request $request): array
    {
        $date = Carbon::parse($filters['date'] ?? ReminderClock::today()->toDateString(), ReminderSetting::TIMEZONE)->startOfDay();
        $day = $date->toDateString();
        $listFilters = array_diff_key($filters, array_flip(['date', 'date_from', 'date_to', 'status']));

        $items = fn (): Builder => $this->scope->filter($this->scope->visible(ReminderQueueItem::query(), $user), $listFilters, 'fire_date')->where('fire_date', $day);
        $messages = fn (bool $withChannel = true): Builder => $this->scope->filter($this->scope->visible(ReminderMessage::query(), $user), $listFilters, 'send_date', $withChannel);

        $handed = $messages()->where('send_date', $day)->whereIn('status', self::HANDED_OVER)
            ->toBase()->selectRaw('channel, test_mode, COUNT(*) as aggregate')->groupBy('channel', 'test_mode')->get();
        $count = fn (?string $channel, bool $logged) => (int) $handed
            ->filter(fn ($row) => ($channel === null || $row->channel === $channel) && (bool) $row->test_mode === $logged)
            ->sum('aggregate');

        $monthSms = fn (): Builder => $messages(false)->where('channel', 'sms')
            ->whereBetween('send_date', [$date->copy()->startOfMonth()->toDateString(), $date->copy()->endOfMonth()->toDateString()]);

        return [
            'counts' => [
                'today' => $items()->count(),
                'sent' => $count(null, false),
                'logged' => $count(null, true),
                'pending' => $items()->whereIn('status', ['scheduled', 'queued', 'processing'])->count(),
                'failed' => $items()->where('status', 'failed')->count(),
                'sms_sent' => $count('sms', false),
                'sms_logged' => $count('sms', true),
                'emails_sent' => $count('email', false),
                'emails_logged' => $count('email', true),
                'upcoming_due' => $items()->where('due_date', '>=', $day)->distinct()->count('loan_id'),
                'overdue' => $items()->where('due_date', '<', $day)->distinct()->count('loan_id'),
            ],
            'sms' => [
                'credits_remaining' => null,
                'low_credit_threshold' => null,
                'sent_this_month' => $monthSms()->whereIn('status', self::HANDED_OVER)->where('test_mode', false)->count(),
                'logged_this_month' => $monthSms()->whereIn('status', self::HANDED_OVER)->where('test_mode', true)->count(),
                'estimated_cost_this_month' => round((float) $monthSms()->sum('cost'), 2),
            ],
            'daily' => $this->daily($messages(), $date),
            'items' => ReminderQueueItemResource::collection(
                $items()
                    ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->where('status', $filters['status']))
                    ->with(ReminderLists::QUEUE_RELATIONS)
                    ->orderBy('scheduled_at')->orderBy('id')
                    ->limit(25)->get(),
            )->resolve($request),
            'global_pause' => $this->globalPause($request),
            'test_mode' => $this->mode->testMode(),
            'enabled' => (bool) ReminderSetting::current()->enabled,
        ];
    }

    /**
     * @param  Builder<ReminderMessage>  $messages
     * @return list<array{date: string, sent: int, logged: int, delivered: int, failed: int}>
     */
    private function daily(Builder $messages, Carbon $date): array
    {
        $from = $date->copy()->subDays(6);
        $rows = $messages->whereBetween('send_date', [$from->toDateString(), $date->toDateString()])
            ->toBase()->selectRaw('send_date, status, test_mode, COUNT(*) as aggregate')
            ->groupBy('send_date', 'status', 'test_mode')->get()
            ->map(fn ($row) => ['day' => substr((string) $row->send_date, 0, 10), 'status' => $row->status, 'logged' => (bool) $row->test_mode, 'count' => (int) $row->aggregate]);

        $days = [];

        for ($day = $from->copy(); $day->lte($date); $day->addDay()) {
            $on = $rows->where('day', $day->toDateString());
            $handed = $on->filter(fn (array $row) => in_array($row['status'], self::HANDED_OVER, true));

            $days[] = [
                'date' => $day->toDateString(),
                'sent' => (int) $handed->where('logged', false)->sum('count'),
                'logged' => (int) $handed->where('logged', true)->sum('count'),
                'delivered' => (int) $on->where('logged', false)->whereIn('status', ['delivered', 'opened'])->sum('count'),
                'failed' => (int) $on->filter(fn (array $row) => in_array($row['status'], self::FAILED, true))->sum('count'),
            ];
        }

        return $days;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function globalPause(Request $request): ?array
    {
        $pause = ReminderPause::query()->inForce()->where('scope', 'global')->with(ReminderPauseResource::RELATIONS)->latest('paused_at')->latest('id')->first();

        return $pause === null ? null : (new ReminderPauseResource($pause))->resolve($request);
    }
}
