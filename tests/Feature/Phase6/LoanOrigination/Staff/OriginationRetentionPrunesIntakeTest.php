<?php

namespace Tests\Feature\Phase6\LoanOrigination\Staff;

use App\Models\OriginationApplication;
use App\Models\OriginationLink;
use App\Services\LoanOrigination\Staff\RetentionSweep;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * LO-B3: loan-origination:retention also prunes LO-B2's applicant codes
 * (`origination_otp_challenges`, 2 days after they were made) and applicant
 * sessions (`origination_sessions`, a day after they ended or expired),
 * reading them by table name and skipping a table that doesn't exist.
 *
 * Until LO-B2 merges, this branch has no such tables, so the test creates
 * them with LO-B2's columns (its 2026_11_19_400200 migration) when they're
 * missing. That is DDL, so the test runs outside the per-test transaction;
 * the next transactional test rebuilds the database from scratch.
 */
class OriginationRetentionPrunesIntakeTest extends TestCase
{
    protected bool $wrapsEachTestInTransaction = false;

    private bool $createdTables = false;

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('origination_sessions');
            Schema::dropIfExists('origination_otp_challenges');
        }

        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_deletes_old_codes_and_ended_sessions_and_keeps_the_rest(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-19 10:25:00', 'Asia/Manila'));
        $this->createIntakeTablesIfMissing();

        $link = OriginationLink::query()->create(['name' => 'Test link', 'token' => OriginationLink::newToken()]);
        $application = OriginationApplication::query()->create(['link_id' => $link->id]);

        $code = fn (Carbon $made) => DB::table('origination_otp_challenges')->insertGetId([
            'link_id' => $link->id, 'purpose' => 'apply', 'channel' => 'sms', 'mobile_hash' => str_repeat('a', 64),
            'code_hash' => 'x', 'expires_at' => $made->copy()->addMinutes(5), 'created_at' => $made,
        ]);
        $session = fn (Carbon $expires, ?Carbon $ended) => DB::table('origination_sessions')->insertGetId([
            'application_id' => $application->id, 'link_id' => $link->id, 'purpose' => 'apply', 'token_hash' => bin2hex(random_bytes(32)),
            'last_used_at' => $expires->copy()->subMinutes(30), 'expires_at' => $expires, 'ended_at' => $ended, 'created_at' => $expires->copy()->subHours(4),
        ]);

        $oldCode = $code(now()->subDays(3));
        $todayCode = $code(now()->subHours(3));
        $endedLongAgo = $session(now()->addHours(2), now()->subDays(2));
        $expiredLongAgo = $session(now()->subDays(2), null);
        $live = $session(now()->addHour(), null);
        $endedJustNow = $session(now()->addHours(3), now()->subHour());

        $this->artisan('loan-origination:retention --dry-run')
            ->expectsOutputToContain('Would delete old applicant codes: 1')
            ->expectsOutputToContain('Would delete ended applicant sessions: 2')
            ->assertSuccessful();
        $this->assertSame(2, DB::table('origination_otp_challenges')->count());

        $result = app(RetentionSweep::class)->run(false);

        $this->assertSame(1, $result['codes']);
        $this->assertSame(2, $result['sessions']);
        $this->assertSame([$todayCode], DB::table('origination_otp_challenges')->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$live, $endedJustNow], DB::table('origination_sessions')->pluck('id')->all());
        $this->assertNotContains($oldCode, DB::table('origination_otp_challenges')->pluck('id')->all());
        $this->assertNotContains($endedLongAgo, DB::table('origination_sessions')->pluck('id')->all());
        $this->assertNotContains($expiredLongAgo, DB::table('origination_sessions')->pluck('id')->all());
    }

    /** LO-B2's two tables, as its migration makes them, when this branch has none. */
    private function createIntakeTablesIfMissing(): void
    {
        if (Schema::hasTable('origination_otp_challenges')) {
            return;
        }

        $this->createdTables = true;

        Schema::create('origination_otp_challenges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('link_id')->constrained('origination_links')->cascadeOnDelete();
            $table->enum('purpose', ['apply', 'resume', 'track']);
            $table->enum('channel', ['sms']);
            $table->char('mobile_hash', 64);
            $table->string('reference_number', 30)->nullable();
            $table->string('code_hash', 255);
            $table->boolean('test_mode')->default(false);
            $table->boolean('delivered')->default(false);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('origination_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('origination_applications')->cascadeOnDelete();
            $table->foreignId('link_id')->constrained('origination_links')->cascadeOnDelete();
            $table->enum('purpose', ['apply', 'resume', 'track']);
            $table->char('token_hash', 64)->unique();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_used_at');
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
