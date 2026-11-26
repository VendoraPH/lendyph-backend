<?php

use App\Services\TokenTimeout;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * "Remember me for 30 days" is removed (finish run round 7, Pepito's QA task
 * 14zfbg0eqfd, security), so remembered sign-ins still open are cut to the
 * normal session length when this deploys.
 *
 * Every staff token (`tokenable_type` App\Models\User) whose `expires_at` is
 * beyond now plus AUTH_TOKEN_TIMEOUT minutes, or null (never), is capped at
 * that limit. Nothing is revoked, but nothing is renewed either: a browser
 * that ticked "Remember me" ran no idle timer and no renewal, so it is signed
 * out when the cap is reached and signs in again. Member portal
 * tokens are never touched (their own 60-minute rule). One audit row, a system
 * change, counts what was shortened, remembered sign-ins and sign-ins with no
 * end apart; when nothing was, nothing is written, so
 * a second run changes nothing and writes nothing (a token capped by the first
 * run expires no later than now plus the session length).
 *
 * Written with the query builder alone, so it writes on its last deployment
 * what it wrote on its first.
 */
return new class extends Migration
{
    private const STAFF_TOKENABLE = 'App\Models\User';

    private const ACTION = 'remembered_sign_ins_shortened';

    public function up(): void
    {
        $minutes = TokenTimeout::minutes();

        $shortened = DB::transaction(function () use ($minutes): int {
            $limit = now()->addMinutes($minutes);

            $staff = fn () => DB::table('personal_access_tokens')->where('tokenable_type', self::STAFF_TOKENABLE);

            $remembered = $staff()->where('expires_at', '>', $limit)->update(['expires_at' => $limit, 'updated_at' => now()]);
            $neverExpiring = $staff()->whereNull('expires_at')->update(['expires_at' => $limit, 'updated_at' => now()]);
            $count = $remembered + $neverExpiring;

            if ($count > 0) {
                DB::table('audit_logs')->insert([
                    'user_id' => null,
                    'action' => self::ACTION,
                    'auditable_type' => null,
                    'auditable_id' => null,
                    'old_values' => null,
                    'new_values' => json_encode(['remembered' => $remembered, 'never_expiring' => $neverExpiring, 'session_minutes' => $minutes]),
                    'ip_address' => null,
                    'user_agent' => null,
                    'description' => sprintf(
                        'System change: %s shortened to the normal session length (%d minutes).',
                        implode(' and ', array_filter([
                            $remembered > 0 ? sprintf('%d remembered sign-%s', $remembered, $remembered === 1 ? 'in' : 'ins') : null,
                            $neverExpiring > 0 ? sprintf('%d sign-%s with no end', $neverExpiring, $neverExpiring === 1 ? 'in' : 'ins') : null,
                        ])),
                        $minutes,
                    ),
                    'created_at' => now(),
                ]);
            }

            return $count;
        });

        $summary = sprintf('remembered sign-ins: %d shortened to %d minutes', $shortened, $minutes);

        Log::info($summary);

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    /**
     * Nothing to undo: a shortened sign-in can't be lengthened again, and
     * "Remember me" is gone.
     */
    public function down(): void {}
};
