<?php

use App\Services\ExtensionPeriodRebuilder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Put back the partly paid periods extensions deleted before 2026-10-02.
     *
     * The logic, and what it refuses to guess, is ExtensionPeriodRebuilder's.
     * `php artisan loans:rebuild-extension-periods --dry-run` shows what this
     * will change on a database before it is deployed there.
     */
    public function up(): void
    {
        $result = app(ExtensionPeriodRebuilder::class)->run(dryRun: false);

        Log::info(sprintf(
            'Rebuilt periods extensions deleted: %d period(s) on %d loan(s), %d loan(s) with missing payments left as they are.',
            collect($result['rebuilt'])->sum(fn (array $loan) => count($loan['periods'])),
            count($result['rebuilt']),
            count($result['skipped']),
        ), $result);
    }

    /**
     * The rebuilt periods carry payments' allocation rows, which cannot be
     * told apart from rows payments wrote for themselves, so they all stay.
     */
    public function down(): void {}
};
