<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The late penalty is charged every month a period stays unpaid (owner's
     * answer, Arvin, 2026-11-11). RepaymentService::penaltyFor() holds the rule.
     *
     * - `late_penalty_settings.monthly_from` is the switch date: today in
     *   Manila, stored on a deployment that already holds amortization
     *   schedules. A period already late before it keeps the one charge it
     *   had and adds one for each full month after the switch. A fresh
     *   database has no such period, so it stores no date and every period
     *   follows the monthly rule.
     * - `amortization_schedules.penalty_months` is the month count last
     *   charged, for the screen (Penalty: N month(s) late).
     * - `penalty_cleared_on` and `penalty_cleared_to` record a penalty waiver
     *   made from now on (option b): the day it cleared the penalty and the
     *   charge it left (what was already paid). Months after it are charged
     *   again. A waiver made before the switch has neither and keeps its
     *   period penalty-free (option a).
     *
     * No charge is changed here. Storing the switch date writes one system
     * audit row, naming the date and how many open periods were already late.
     * Safe to run twice: each step checks whether it already ran.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('amortization_schedules', 'penalty_months')) {
            Schema::table('amortization_schedules', function (Blueprint $table): void {
                $table->unsignedSmallInteger('penalty_months')->default(0)->after('penalty_paid');
                $table->date('penalty_cleared_on')->nullable()->after('penalty_waiver_id');
                $table->decimal('penalty_cleared_to', 12, 2)->nullable()->after('penalty_cleared_on');
            });
        }

        if (! Schema::hasTable('late_penalty_settings')) {
            Schema::create('late_penalty_settings', function (Blueprint $table): void {
                $table->id();
                $table->date('monthly_from');
            });
        }

        if (DB::table('late_penalty_settings')->exists() || ! DB::table('amortization_schedules')->exists()) {
            return;
        }

        $switchDate = Carbon::now('Asia/Manila')->toDateString();

        DB::transaction(function () use ($switchDate): void {
            DB::table('late_penalty_settings')->insert(['monthly_from' => $switchDate]);

            // Open periods already late the day before the switch, by the
            // grace rule (AmortizationSchedule::pastGraceCutoff()).
            $alreadyLate = DB::table('amortization_schedules as s')
                ->join('loans as l', 'l.id', '=', 's.loan_id')
                ->whereIn('s.status', ['pending', 'partial', 'overdue'])
                ->whereRaw('s.due_date < DATE_SUB(DATE_SUB(?, INTERVAL 1 DAY), INTERVAL GREATEST(COALESCE(l.grace_period_days, 0), 0) DAY)', [$switchDate])
                ->count();

            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'late_penalty_monthly_switch',
                'auditable_type' => null,
                'auditable_id' => null,
                'old_values' => json_encode(['rule' => 'charged once']),
                'new_values' => json_encode(['rule' => 'charged every month', 'monthly_from' => $switchDate, 'periods_already_late' => $alreadyLate]),
                'ip_address' => null,
                'user_agent' => null,
                'description' => sprintf(
                    'System change: the late penalty is charged every month a period stays unpaid, from %s. %d open period(s) already late keep their charge and add one for each full month after that date.',
                    $switchDate,
                    $alreadyLate,
                ),
                'created_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('late_penalty_settings');

        if (Schema::hasColumn('amortization_schedules', 'penalty_months')) {
            Schema::table('amortization_schedules', function (Blueprint $table): void {
                $table->dropColumn(['penalty_months', 'penalty_cleared_on', 'penalty_cleared_to']);
            });
        }
    }
};
