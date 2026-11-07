<?php

use App\Services\RoleMemberDescriptionReword;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

return new class extends Migration
{
    /**
     * Reword the admin and loan_officer descriptions from "borrowers" to
     * "members" where they still hold the old seeded text.
     *
     * Runs RoleMemberDescriptionReword, whose docblock explains the rule, so it
     * writes exactly what `php artisan roles:reword-member-descriptions
     * --dry-run` shows first. An edited description is left alone and logged,
     * each change writes one audit row as a system change, and a second run
     * changes nothing. On a fresh database the roles do not exist yet and the
     * seeder writes the new text, so this does nothing there.
     *
     * The summary line is printed as well as logged, so the count shows in the
     * deploy log. Not under the test suite, which runs every migration on a
     * fresh database for each worker and would print it each time.
     */
    public function up(): void
    {
        $result = app(RoleMemberDescriptionReword::class)->run(false);
        $summary = RoleMemberDescriptionReword::summary($result);

        Log::info($summary, $result);

        if ($result['skipped'] !== []) {
            Log::warning('Role descriptions that were edited were left unchanged.', ['roles' => $result['skipped']]);
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    /**
     * Nothing to undo: the old text said "borrowers", which the app no longer
     * uses, and audit rows are never deleted.
     */
    public function down(): void {}
};
