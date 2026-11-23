<?php

namespace App\Services\LoanOrigination\Staff;

use App\Exceptions\OriginationErasureRefusedException;
use App\Models\OriginationApplication;
use App\Models\OriginationSetting;
use App\Services\AuditLogService;
use App\Services\LoanOrigination\ApplicationTransitions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The nightly expiry and retention sweep for online applications
 * (origination decisions 10 and 18), in this order:
 *
 * 1. a draft with no save for OriginationSetting::INCOMPLETE_AFTER_HOURS
 *    (24) becomes Incomplete;
 * 2. an unsubmitted application (draft or incomplete) idle for the settings'
 *    `draft_expiration_days` (30) expires;
 * 3. an expired application is erased EXPIRED_ERASE_AFTER_DAYS (30) after it
 *    expired;
 * 4. a rejected or withdrawn one is erased the settings' `retention_days`
 *    (365, to verify) after its decision.
 *
 * "Idle" is the last save, else the start, else the row's creation. Moves go
 * through ApplicationTransitions as the system; an application the applicant
 * saved meanwhile (409) is skipped and picked up the next night. Erasure is
 * the model's eraseForRetention(): a legal hold blocks it (skipped and
 * listed), and converted applications are never touched (they stay with
 * their loan, as is any application that has a loan id: one whose loan was
 * rejected or voided after the hand-off reads rejected or withdrawn but stays
 * with that loan). Its staff notices go with it, since they name the
 * applicant. Each erasure writes a system audit row holding ids, the final
 * status and the decision date only, so the counts survive the erasure.
 *
 * 5. LO-B2's applicant codes (`origination_otp_challenges`) are deleted
 *    CODES_KEPT_DAYS (2) after they were made, past the hourly limits and the
 *    Manila day the daily send cap counts, and its applicant sessions
 *    (`origination_sessions`) SESSIONS_KEPT_DAYS (1) after they ended or
 *    expired. Read by table name, and skipped while a table doesn't exist,
 *    so this works before and after LO-B2 merges.
 *
 * A dry run reads the same rows and writes nothing. Safe to run twice.
 */
final class RetentionSweep
{
    public const EXPIRED_ERASE_AFTER_DAYS = 30;

    public const CODES_KEPT_DAYS = 2;

    public const SESSIONS_KEPT_DAYS = 1;

    private const CHUNK = 200;

    private const DELETE_CHUNK = 1000;

    public function __construct(private readonly ApplicationTransitions $transitions) {}

    /**
     * @return array{incomplete: list<int>, expired: list<int>, erased: list<int>, held: list<int>, skipped: list<int>, codes: int, sessions: int}
     */
    public function run(bool $dryRun): array
    {
        $settings = OriginationSetting::current();
        $now = Carbon::now();
        $result = ['incomplete' => [], 'expired' => [], 'erased' => [], 'held' => [], 'skipped' => [], 'codes' => 0, 'sessions' => 0];

        $expireBefore = $now->copy()->subDays(max(1, (int) $settings->draft_expiration_days));
        $this->each(
            $this->idleBefore(OriginationApplication::query()->whereIn('status', OriginationApplication::UNSUBMITTED_STATUSES), $expireBefore),
            function (OriginationApplication $a) use ($dryRun, &$result): void {
                $this->move($a, 'expired', $dryRun, $result['expired'], $result['skipped']);
            },
        );

        $this->each(
            $this->idleBefore(OriginationApplication::query()->where('status', 'draft'), $now->copy()->subHours(OriginationSetting::INCOMPLETE_AFTER_HOURS))
                ->whereNotIn('id', $result['expired']),
            function (OriginationApplication $a) use ($dryRun, &$result): void {
                $this->move($a, 'incomplete', $dryRun, $result['incomplete'], $result['skipped']);
            },
        );

        $this->each(
            OriginationApplication::query()->where('status', 'expired')->whereNull('loan_id')->where('decided_at', '<', $now->copy()->subDays(self::EXPIRED_ERASE_AFTER_DAYS)),
            function (OriginationApplication $a) use ($dryRun, &$result): void {
                $this->erase($a, $dryRun, $result);
            },
        );

        $this->each(
            OriginationApplication::query()->whereIn('status', ['rejected', 'withdrawn'])
                // A handed-off application whose loan was later rejected or
                // voided reads rejected or withdrawn too, but stays with its
                // loan, as a converted one does.
                ->whereNull('loan_id')
                ->where('decided_at', '<', $now->copy()->subDays(max(1, (int) $settings->retention_days))),
            function (OriginationApplication $a) use ($dryRun, &$result): void {
                $this->erase($a, $dryRun, $result);
            },
        );

        $result['codes'] = $this->prune('origination_otp_challenges', $dryRun, fn (QueryBuilder $q) => $q
            ->where('created_at', '<', $now->copy()->subDays(self::CODES_KEPT_DAYS)));

        $result['sessions'] = $this->prune('origination_sessions', $dryRun, fn (QueryBuilder $q) => $q
            ->whereRaw('COALESCE(ended_at, expires_at) < ?', [$now->copy()->subDays(self::SESSIONS_KEPT_DAYS)]));

        return $result;
    }

    /**
     * Delete a table's rows matching $where in chunks, each its own
     * statement, so a first run never holds one long lock. Nothing while the
     * table doesn't exist.
     *
     * @param  \Closure(QueryBuilder): mixed  $where
     */
    private function prune(string $table, bool $dryRun, \Closure $where): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $rows = fn (): QueryBuilder => DB::table($table)->tap($where);

        if ($dryRun) {
            return $rows()->count();
        }

        $total = 0;

        do {
            $deleted = $rows()->orderBy('id')->limit(self::DELETE_CHUNK)->delete();
            $total += $deleted;
        } while ($deleted === self::DELETE_CHUNK);

        return $total;
    }

    /**
     * @param  Builder<OriginationApplication>  $query
     * @return Builder<OriginationApplication>
     */
    private function idleBefore(Builder $query, Carbon $before): Builder
    {
        return $query->whereRaw('COALESCE(last_saved_at, started_at, created_at) < ?', [$before]);
    }

    /**
     * @param  Builder<OriginationApplication>  $query
     * @param  \Closure(OriginationApplication): void  $step
     */
    private function each(Builder $query, \Closure $step): void
    {
        $query->select(['id', 'status', 'version', 'branch_id', 'submitted_at', 'decided_at', 'legal_hold'])
            ->chunkById(self::CHUNK, fn ($applications) => $applications->each($step));
    }

    /**
     * @param  list<int>  $done
     * @param  list<int>  $skipped
     */
    private function move(OriginationApplication $application, string $to, bool $dryRun, array &$done, array &$skipped): void
    {
        if ($dryRun) {
            $done[] = (int) $application->id;

            return;
        }

        try {
            $this->transitions->move($application, $to, ApplicationTransitions::ACTOR_SYSTEM);
            $done[] = (int) $application->id;
        } catch (HttpResponseException) {
            $skipped[] = (int) $application->id;
        }
    }

    /**
     * @param  array{incomplete: list<int>, expired: list<int>, erased: list<int>, held: list<int>, skipped: list<int>, codes: int, sessions: int}  $result
     */
    private function erase(OriginationApplication $application, bool $dryRun, array &$result): void
    {
        if ($application->legal_hold) {
            $result['held'][] = (int) $application->id;

            return;
        }

        if ($dryRun) {
            $result['erased'][] = (int) $application->id;

            return;
        }

        $summary = [
            'status' => $application->status,
            'branch_id' => $application->branch_id,
            'submitted' => $application->submitted_at !== null,
            'decided_on' => $application->decided_at?->toDateString(),
        ];

        try {
            $application->eraseForRetention();
        } catch (OriginationErasureRefusedException) {
            $result['held'][] = (int) $application->id;

            return;
        }

        DB::table('notifications')
            ->whereIn('type', OriginationStaffNotices::TYPES)
            ->where('data->data->application_id', (int) $application->id)
            ->delete();

        AuditLogService::log('origination_erased', $application, $summary, null, 'Erased an online application at the end of its retention period.', asSystem: true);
        $result['erased'][] = (int) $application->id;
    }
}
