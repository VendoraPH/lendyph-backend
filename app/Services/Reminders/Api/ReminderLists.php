<?php

namespace App\Services\Reminders\Api;

use App\Models\ReminderMessage;
use App\Models\ReminderQueueItem;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The queue and message history lists: the caller's visible rows, filtered,
 * paged (25 by default, between 1 and 100), with `stats` counting each status
 * for the same filters without `status`, so every tab keeps its total.
 */
final class ReminderLists
{
    public const QUEUE_STATUSES = ['scheduled', 'queued', 'processing', 'sent', 'delivered', 'failed', 'cancelled', 'skipped'];

    public const MESSAGE_STATUSES = ['pending', 'sent', 'delivered', 'failed', 'rejected', 'bounced', 'opened'];

    public const QUEUE_RELATIONS = ['loan:id,loan_account_number,application_number', 'borrower:id,first_name,middle_name,last_name,suffix,contact_number,email', 'rule:id,name', 'message:id,queue_item_id,test_mode'];

    public const MESSAGE_RELATIONS = ['loan:id,loan_account_number,application_number', 'borrower:id,first_name,middle_name,last_name,suffix', 'createdBy:id,first_name,last_name'];

    public function __construct(private readonly ReminderScope $scope) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: LengthAwarePaginator, 1: array<string, int>}
     */
    public function queue(User $user, array $filters): array
    {
        $base = $this->scope->filter($this->scope->visible(ReminderQueueItem::query(), $user), $filters, 'fire_date');

        $page = (clone $base)
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->where('status', $filters['status']))
            ->with(self::QUEUE_RELATIONS)
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->paginate(self::perPage($filters));

        return [$page, self::stats($base, self::QUEUE_STATUSES)];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: LengthAwarePaginator, 1: array<string, int>}
     */
    public function history(User $user, array $filters): array
    {
        $base = $this->scope->filter($this->scope->visible(ReminderMessage::query(), $user), $filters, 'send_date');

        $page = (clone $base)
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->where('status', $filters['status']))
            ->with(self::MESSAGE_RELATIONS)
            ->orderByDesc('id')
            ->paginate(self::perPage($filters));

        return [$page, self::stats($base, self::MESSAGE_STATUSES)];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function perPage(array $filters): int
    {
        return min(max((int) ($filters['per_page'] ?? 25), 1), 100);
    }

    /**
     * @param  Builder<Model>  $base
     * @param  list<string>  $statuses
     * @return array<string, int>
     */
    private static function stats(Builder $base, array $statuses): array
    {
        $counts = (clone $base)->toBase()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return collect($statuses)->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)])->all();
    }
}
