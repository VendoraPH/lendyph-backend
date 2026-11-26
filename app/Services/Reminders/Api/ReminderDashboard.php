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
use Illuminate\Database\Eloquent\Collection;
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
 *
 * counts() and failed() read the same figures for a range of days (Linda
 * round 4): the day's counts are counts() over that one day.
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
        $listFilters = self::listFilters($filters);

        $items = fn (): Builder => $this->items($user, $listFilters, $day, $day);
        $messages = fn (bool $withChannel = true): Builder => $this->scope->filter($this->scope->visible(ReminderMessage::query(), $user), $listFilters, 'send_date', $withChannel);

        $monthSms = fn (): Builder => $messages(false)->where('channel', 'sms')
            ->whereBetween('send_date', [$date->copy()->startOfMonth()->toDateString(), $date->copy()->endOfMonth()->toDateString()]);

        return [
            'counts' => $this->counts($user, $filters, $day, $day),
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
            ...$this->mode(),
        ];
    }

    /**
     * The dashboard's counts for the days `$from` to `$to` (Manila, both
     * included), over the caller's visible loans and the list filters:
     * reminders planned for those days (`today`), pending and failed among
     * them, messages handed over on them, "sent" apart from "logged" and by
     * channel, and the loans whose period was due on its reminder's day or
     * later (`upcoming_due`) or before it (`overdue`).
     *
     * @param  array<string, mixed>  $filters
     * @return array{today: int, sent: int, logged: int, pending: int, failed: int, sms_sent: int, sms_logged: int, emails_sent: int, emails_logged: int, upcoming_due: int, overdue: int}
     */
    public function counts(User $user, array $filters, string $from, string $to): array
    {
        $listFilters = self::listFilters($filters);
        $items = fn (): Builder => $this->items($user, $listFilters, $from, $to);

        $handed = $this->scope->filter($this->scope->visible(ReminderMessage::query(), $user), $listFilters, 'send_date')
            ->whereBetween('send_date', [$from, $to])->whereIn('status', self::HANDED_OVER)
            ->toBase()->selectRaw('channel, test_mode, COUNT(*) as aggregate')->groupBy('channel', 'test_mode')->get();
        $count = fn (?string $channel, bool $logged) => (int) $handed
            ->filter(fn ($row) => ($channel === null || $row->channel === $channel) && (bool) $row->test_mode === $logged)
            ->sum('aggregate');

        return [
            'today' => $items()->count(),
            'sent' => $count(null, false),
            'logged' => $count(null, true),
            'pending' => $items()->whereIn('status', ['scheduled', 'queued', 'processing'])->count(),
            'failed' => $items()->where('status', 'failed')->count(),
            'sms_sent' => $count('sms', false),
            'sms_logged' => $count('sms', true),
            'emails_sent' => $count('email', false),
            'emails_logged' => $count('email', true),
            'upcoming_due' => $items()->whereColumn('due_date', '>=', 'fire_date')->distinct()->count('loan_id'),
            'overdue' => $items()->whereColumn('due_date', '<', 'fire_date')->distinct()->count('loan_id'),
        ];
    }

    /**
     * The failed reminders of the days `$from` to `$to` (the ones counts()
     * counts as `failed`), the latest day first and in the dashboard's order
     * within a day, at most `$limit`, with their member and loan.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, ReminderQueueItem>
     */
    public function failed(User $user, array $filters, string $from, string $to, int $limit): Collection
    {
        return $this->items($user, self::listFilters($filters), $from, $to)
            ->where('status', 'failed')
            ->with(['loan:id,loan_account_number,external_loan_no,application_number', 'borrower:id,branch_id,first_name,middle_name,last_name,suffix'])
            ->orderByDesc('fire_date')->orderBy('scheduled_at')->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Whether this deployment only logs its messages (`test_mode`), and
     * whether reminders are on (`enabled`).
     *
     * @return array{test_mode: bool, enabled: bool}
     */
    public function mode(): array
    {
        return ['test_mode' => $this->mode->testMode(), 'enabled' => (bool) ReminderSetting::current()->enabled];
    }

    /**
     * The visible queue rows planned for the days `$from` to `$to`.
     *
     * @param  array<string, mixed>  $listFilters
     * @return Builder<ReminderQueueItem>
     */
    private function items(User $user, array $listFilters, string $from, string $to): Builder
    {
        return $this->scope->filter($this->scope->visible(ReminderQueueItem::query(), $user), $listFilters, 'fire_date')->whereBetween('fire_date', [$from, $to]);
    }

    /**
     * The filters the counts apply: every one but the dates and `status`.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private static function listFilters(array $filters): array
    {
        return array_diff_key($filters, array_flip(['date', 'date_from', 'date_to', 'status']));
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
