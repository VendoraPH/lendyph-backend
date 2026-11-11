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
     * - `amortization_schedules.penalty_months` is the number of monthly
     *   charges counted in the period's penalty figure, for the screen
     *   (Penalty: N month(s)).
     * - `penalty_checkpoint_on`, `penalty_checkpoint_amount` and
     *   `penalty_checkpoint_months` fix what a period had been charged by a
     *   day: the charge and the number of charges in it. A penalty waiver
     *   made from now on (option b) leaves the charge at what was paid, and a
     *   balance adjustment keeps the charge worked on the old principal; the
     *   months after that day are charged on the period as it then stands. A
     *   waiver made before the switch has no checkpoint and keeps its period
     *   penalty-free (option a).
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
                $table->date('penalty_checkpoint_on')->nullable()->after('penalty_waiver_id');
                $table->decimal('penalty_checkpoint_amount', 12, 2)->nullable()->after('penalty_checkpoint_on');
                $table->unsignedSmallInteger('penalty_checkpoint_months')->nullable()->after('penalty_checkpoint_amount');
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
            // grace rule (AmortizationSchedule::pastGraceCutoff()), that the
            // nightly run charges (loans:apply-penalties): on a collectible
            // loan with a penalty rate, after any imported arrears baseline,
            // and not waived. Spelled out so the count never moves with
            // those classes.
            $alreadyLate = DB::table('amortization_schedules as s')
                ->join('loans as l', 'l.id', '=', 's.loan_id')
                ->whereIn('l.status', ['released', 'ongoing'])
                ->where('l.penalty_rate', '>', 0)
                ->whereRaw('(l.imported_arrears_baseline IS NULL OR s.due_date >= l.imported_arrears_baseline)')
                ->whereNull('s.penalty_waiver_id')
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
                $table->dropColumn(['penalty_months', 'penalty_checkpoint_on', 'penalty_checkpoint_amount', 'penalty_checkpoint_months']);
            });
        }
    }
};
