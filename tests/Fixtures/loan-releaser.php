<?php

/**
 * One loan release, in its own PHP process, by MemberTransferLockTest, so it
 * can be truly concurrent with a member transfer the test runs in its own
 * process.
 *
 * Arguments: the loan id, the staff user id, a marker file path and a pause
 * in microseconds. Once the release holds its first loan row lock (before it
 * reaches the member through FormerMemberGuard), it writes the marker and
 * pauses, so the test can start the transfer inside exactly that window.
 * Prints `ok <status> at <microtime>` (when the release committed) and exits
 * 0, or prints the exception and exits 1.
 *
 * Its database comes from the environment the test passes.
 */

use App\Models\Loan;
use App\Models\User;
use App\Services\LoanService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $loanId, $userId, $marker, $pause] = $argv;

$paused = false;

DB::listen(function (QueryExecuted $query) use (&$paused, $marker, $pause): void {
    $sql = strtolower($query->sql);

    if ($paused || ! str_contains($sql, 'from `loans`') || ! str_contains($sql, 'for update')) {
        return;
    }

    $paused = true;
    file_put_contents($marker, 'locked');
    usleep((int) $pause);
});

try {
    $released = app(LoanService::class)->release(Loan::query()->findOrFail((int) $loanId), User::query()->findOrFail((int) $userId));

    fwrite(STDOUT, sprintf('ok %s at %.6F', $released->fresh()->status, microtime(true)));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, $e::class.': '.$e->getMessage());
    exit(1);
}
