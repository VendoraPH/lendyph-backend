<?php

/**
 * The opening balances screen's finalise, run in its own PHP process by
 * OpeningBalanceLockOrderTest, so it can be truly concurrent with the
 * restructure write-off (J2) the test runs in its own process.
 *
 * Arguments: the draft id, its edit_version, the acting user's id, a marker
 * file path, and a pause in microseconds. Once the finalise has made its
 * locking check for an existing opening balance (it holds the allowance,
 * 3050 and draft row locks and the `source` gap by then), it writes the
 * marker and pauses, so the test can start J2 inside exactly that window.
 * Prints `ok JE-… at <microtime>` (when the finalise finished) and exits 0,
 * or prints the exception and exits 1.
 *
 * Its database comes from the environment the test passes.
 */

use App\Models\AccountingOpeningBalance;
use App\Services\Accounting\OpeningBalancePoster;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../../vendor/autoload.php';

$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $draftId, $editVersion, $userId, $marker, $pause] = $argv;

$paused = false;

DB::listen(function (QueryExecuted $query) use (&$paused, $marker, $pause): void {
    $sql = strtolower($query->sql);

    if ($paused
        || ! str_starts_with($sql, 'select * from `accounting_journals`')
        || ! str_contains($sql, 'for update')
        || ! in_array('opening_balance', $query->bindings, true)) {
        return;
    }

    $paused = true;
    file_put_contents($marker, 'locked');
    usleep((int) $pause);
});

try {
    $draft = AccountingOpeningBalance::query()->findOrFail((int) $draftId);
    $journal = app(OpeningBalancePoster::class)->finalise($draft, (int) $editVersion, (int) $userId);

    fwrite(STDOUT, sprintf('ok %s at %.6F', $journal->journal_no, microtime(true)));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, $e::class.': '.$e->getMessage());
    exit(1);
}
