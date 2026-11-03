<?php

namespace Tests\Feature\R7Integrity;

use App\Models\User;
use App\Notifications\StaffNotification;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `notifications:prune` (round r7, decision 3): the bell's rows are kept for a
 * while, not forever. Read notifications go after --read-days (90), and any
 * notification after --days (365), read or not. --dry-run only counts.
 */
class PruneNotificationsTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->user->assignRole('loan_officer');
    }

    public function test_it_deletes_read_ones_past_the_read_window_and_any_past_the_outer_window(): void
    {
        $readOld = $this->notification('read, 91 days', daysAgo: 91, read: true);
        $readRecent = $this->notification('read, 89 days', daysAgo: 89, read: true);
        $unreadOld = $this->notification('unread, 200 days', daysAgo: 200, read: false);
        $unreadAncient = $this->notification('unread, 366 days', daysAgo: 366, read: false);
        $readAncient = $this->notification('read, 400 days', daysAgo: 400, read: true);

        $this->artisan('notifications:prune')
            ->expectsOutputToContain('Deleted 2 notification(s) older than 365 days and 1 read notification(s) older than 90 days.')
            ->assertSuccessful();

        $this->assertModelMissing($readOld);
        $this->assertModelMissing($unreadAncient);
        $this->assertModelMissing($readAncient);
        $this->assertModelExists($readRecent);
        $this->assertModelExists($unreadOld);
    }

    public function test_dry_run_counts_the_same_rows_and_deletes_nothing(): void
    {
        $this->notification('read, 91 days', daysAgo: 91, read: true);
        $this->notification('unread, 366 days', daysAgo: 366, read: false);
        $this->notification('unread, 10 days', daysAgo: 10, read: false);

        $this->artisan('notifications:prune --dry-run')
            ->expectsOutputToContain('Would delete 1 notification(s) older than 365 days and 1 read notification(s) older than 90 days.')
            ->assertSuccessful();

        $this->assertSame(3, DatabaseNotification::count());
    }

    public function test_the_windows_are_options(): void
    {
        $readTen = $this->notification('read, 10 days', daysAgo: 10, read: true);
        $unreadForty = $this->notification('unread, 40 days', daysAgo: 40, read: false);
        $unreadTwenty = $this->notification('unread, 20 days', daysAgo: 20, read: false);

        $this->artisan('notifications:prune --read-days=7 --days=30')->assertSuccessful();

        $this->assertModelMissing($readTen);
        $this->assertModelMissing($unreadForty);
        $this->assertModelExists($unreadTwenty);
    }

    public function test_it_deletes_in_chunks_past_one_chunk(): void
    {
        $rows = [];
        foreach (range(1, 1205) as $i) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'type' => StaffNotification::LOAN_RELEASED,
                'notifiable_type' => $this->user->getMorphClass(),
                'notifiable_id' => $this->user->id,
                'data' => '{}',
                'read_at' => now()->subDays(100),
                'created_at' => now()->subDays(100),
                'updated_at' => now()->subDays(100),
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DatabaseNotification::query()->insert($chunk);
        }

        $this->artisan('notifications:prune')
            ->expectsOutputToContain('Deleted 0 notification(s) older than 365 days and 1205 read notification(s) older than 90 days.')
            ->assertSuccessful();

        $this->assertSame(0, DatabaseNotification::count());
    }

    public function test_a_window_below_one_day_is_refused(): void
    {
        $this->notification('read, 1 day', daysAgo: 1, read: true);

        $this->artisan('notifications:prune --read-days=0')->assertFailed();
        $this->artisan('notifications:prune --days=abc')->assertFailed();

        $this->assertSame(1, DatabaseNotification::count());
    }

    public function test_a_window_above_ten_years_is_refused(): void
    {
        $this->notification('read, 1 day', daysAgo: 1, read: true);

        $this->artisan('notifications:prune --days=3651')
            ->expectsOutputToContain('--days must be a whole number of days, from 1 to 3650.')
            ->assertFailed();
        $this->artisan('notifications:prune --read-days=3651')
            ->expectsOutputToContain('--read-days must be a whole number of days, from 1 to 3650.')
            ->assertFailed();
        $this->artisan('notifications:prune --days=3650 --read-days=3650')->assertSuccessful();

        $this->assertSame(1, DatabaseNotification::count());
    }

    public function test_created_at_is_indexed(): void
    {
        $indexed = collect(Schema::getIndexes('notifications'))
            ->contains(fn (array $index) => $index['columns'] === ['created_at']);

        $this->assertTrue($indexed, 'notifications.created_at carries its own index.');
    }

    public function test_it_is_scheduled_daily_at_04_00_manila(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains($event->command ?? '', 'notifications:prune'));

        $this->assertNotNull($event, 'notifications:prune is scheduled.');
        $this->assertSame('0 4 * * *', $event->expression);
        $this->assertSame('Asia/Manila', (string) $event->timezone);
    }

    private function notification(string $title, int $daysAgo, bool $read): DatabaseNotification
    {
        $this->travelTo(now()->subDays($daysAgo));
        $this->user->notify(new StaffNotification(
            StaffNotification::LOAN_RELEASED,
            $title,
            'LN-000001 for Juan Dela Cruz: ₱1,500.00 released.',
            '/loans/7',
            ['loan_id' => 7],
        ));
        $notification = $this->user->notifications()->get()
            ->firstOrFail(fn (DatabaseNotification $n) => $n->data['title'] === $title);
        if ($read) {
            $notification->markAsRead();
        }
        $this->travelBack();

        return $notification;
    }
}
