<?php

namespace Tests\Feature\Linda;

use App\Models\LindaQuestion;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `linda:prune` (Linda v1 plan, "Privacy and the log"): Linda's log is kept
 * `linda.retention_days` (365) days, then deleted in chunks, daily at 04:20
 * Manila. --dry-run only counts.
 */
class PruneLindaQuestionsTest extends TestCase
{
    public function test_it_deletes_rows_past_the_retention_window_and_keeps_the_rest(): void
    {
        $old = $this->question(daysAgo: 366);
        $ancient = $this->question(daysAgo: 900);
        $recent = $this->question(daysAgo: 364);

        $this->artisan('linda:prune')
            ->expectsOutputToContain('Deleted 2 Linda question log row(s) older than 365 days.')
            ->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelMissing($ancient);
        $this->assertModelExists($recent);
    }

    public function test_the_window_defaults_to_the_configured_retention(): void
    {
        config()->set('linda.retention_days', 30);
        $old = $this->question(daysAgo: 31);
        $recent = $this->question(daysAgo: 29);

        $this->artisan('linda:prune')
            ->expectsOutputToContain('older than 30 days')
            ->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    public function test_dry_run_counts_the_same_rows_and_deletes_nothing(): void
    {
        $this->question(daysAgo: 400);
        $this->question(daysAgo: 10);

        $this->artisan('linda:prune --dry-run')
            ->expectsOutputToContain('Would delete 1 Linda question log row(s) older than 365 days.')
            ->assertSuccessful();

        $this->assertSame(2, LindaQuestion::query()->count());
    }

    public function test_the_window_is_an_option(): void
    {
        $old = $this->question(daysAgo: 8);
        $recent = $this->question(daysAgo: 6);

        $this->artisan('linda:prune --days=7')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    public function test_it_deletes_in_chunks_past_one_chunk(): void
    {
        $at = now()->subDays(400)->toDateTimeString();
        $rows = array_fill(0, 1205, [
            'asked_at' => $at, 'source' => LindaQuestion::SOURCE_MATCHER, 'duration_ms' => 1,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('linda_questions')->insert($chunk);
        }

        $this->artisan('linda:prune')
            ->expectsOutputToContain('Deleted 1205 Linda question log row(s) older than 365 days.')
            ->assertSuccessful();

        $this->assertSame(0, LindaQuestion::query()->count());
    }

    public function test_a_window_outside_one_day_to_ten_years_is_refused(): void
    {
        $this->question(daysAgo: 4000);

        $this->artisan('linda:prune --days=0')->assertFailed();
        $this->artisan('linda:prune --days=abc')->assertFailed();
        $this->artisan('linda:prune --days=3651')
            ->expectsOutputToContain('--days must be a whole number of days, from 1 to 3650.')
            ->assertFailed();

        $this->assertSame(1, LindaQuestion::query()->count());

        $this->artisan('linda:prune --days=3650')->assertSuccessful();

        $this->assertSame(0, LindaQuestion::query()->count());
    }

    public function test_it_is_scheduled_daily_at_04_20_manila(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains($event->command ?? '', 'linda:prune'));

        $this->assertNotNull($event, 'linda:prune is scheduled.');
        $this->assertSame('20 4 * * *', $event->expression);
        $this->assertSame('Asia/Manila', (string) $event->timezone);
    }

    private function question(int $daysAgo): LindaQuestion
    {
        return LindaQuestion::query()->create([
            'asked_at' => now()->subDays($daysAgo),
            'source' => LindaQuestion::SOURCE_MATCHER,
            'intent' => 'collection_summary',
            'outcome' => 'answered',
            'duration_ms' => 3,
        ]);
    }
}
