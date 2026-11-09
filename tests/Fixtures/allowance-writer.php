<?php

/**
 * One bookkeeper write on the allowance account, run in its own PHP process
 * by AllowanceLockOrderTest, so it can be truly concurrent with the
 * restructure write-off the test runs in its own process.
 *
 * Arguments: `post` or `reverse`, the journal id, a marker file path, and a
 * pause in microseconds. Once the write has locked its journal row, it writes
 * the marker and holds that lock for the pause, so the test can start the
 * write-off inside exactly that window. Prints `ok JE-…` and exits 0, or
 * prints the exception and exits 1.
 *
 * Its database comes from the environment the test passes (DB_DATABASE is
 * the test's own, which differs per parallel worker).
 */

use App\Models\AccountingJournal;
use App\Services\Accounting\JournalPoster;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $action, $journalId, $marker, $pause] = $argv;

$paused = false;

DB::listen(function (QueryExecuted $query) use (&$paused, $journalId, $marker, $pause): void {
    $sql = strtolower($query->sql);

    if ($paused
        || ! str_starts_with($sql, 'select * from `accounting_journals`')
        || ! str_contains($sql, 'for update')
        || (string) ($query->bindings[0] ?? '') !== $journalId) {
        return;
    }

    $paused = true;
    file_put_contents($marker, 'locked');
    usleep((int) $pause);
});

try {
    $poster = app(JournalPoster::class);
    $journal = AccountingJournal::query()->findOrFail((int) $journalId);

    $result = $action === 'reverse'
        ? $poster->reverse($journal, $journal->date->toDateString(), 'Lock order test')
        : $poster->post($journal);

    fwrite(STDOUT, "ok {$result->journal_no}");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, $e::class.': '.$e->getMessage());
    exit(1);
}
