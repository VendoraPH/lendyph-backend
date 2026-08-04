<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('users:backfill-last-login {--dry-run : Show what would be updated without writing}')]
#[Description('Populate users.last_login_at from the newest login entry in the audit log')]
class BackfillLastLoginAt extends Command
{
    public function handle(): int
    {
        // `last_login_at` was never written because AuthController mass-assigned
        // it against a $fillable that excludes it. The audit log kept a 'login'
        // row per sign-in, so it can reconstruct the real values.
        //
        // Match on auditable_id, not user_id: AuditLogService fills user_id from
        // auth()->id(), which is not yet resolved while the login is being
        // handled, so it is null on those rows.
        $latestLogins = DB::table('audit_logs')
            ->select('auditable_id')
            ->selectRaw('MAX(created_at) as last_login')
            ->where('action', 'login')
            ->where('auditable_type', User::class)
            ->whereNotNull('auditable_id')
            ->groupBy('auditable_id')
            ->pluck('last_login', 'auditable_id');

        // Only ever fill blanks — never overwrite a genuine timestamp, so the
        // command stays idempotent and safe to re-run.
        $users = User::whereNull('last_login_at')->get(['id', 'last_login_at']);

        if ($users->isEmpty()) {
            $this->info('No users are missing a last_login_at value.');

            return self::SUCCESS;
        }

        $resolvable = $users->filter(fn (User $user) => isset($latestLogins[$user->id]));
        $skipped = $users->count() - $resolvable->count();

        if ($resolvable->isEmpty()) {
            $this->info("No login history found for {$skipped} user(s) missing last_login_at.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($resolvable as $user) {
                $this->line("  user {$user->id} -> {$latestLogins[$user->id]}");
            }
            $this->info("Would update {$resolvable->count()} user(s); {$skipped} without login history.");

            return self::SUCCESS;
        }

        // Written through the query builder rather than Eloquent: this is a
        // historical correction, not user activity, so it should neither bump
        // `updated_at` (Eloquent\Builder::update would) nor fire model events —
        // the Auditable trait would otherwise write an audit row per user
        // carrying the bcrypt hash.
        foreach ($resolvable as $user) {
            DB::table('users')
                ->where('id', $user->id)
                ->update(['last_login_at' => $latestLogins[$user->id]]);
        }

        $this->info("Updated {$resolvable->count()} user(s); {$skipped} without login history.");

        return self::SUCCESS;
    }
}
