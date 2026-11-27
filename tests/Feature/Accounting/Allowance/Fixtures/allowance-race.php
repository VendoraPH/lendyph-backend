<?php

/**
 * One side of AllowanceRunLockOrderTest, run in its own PHP process so it is
 * truly concurrent with the side the test runs.
 *
 * Arguments: the action, a marker file path, a pause in microseconds, then
 * the action's own:
 * - `write-off <new loan id> <source loan id> <user id>`: a restructure
 *   release (J1 and the write-off J2) in its own transaction;
 * - `allowance <as_of> <expected adjustment> <user id>`: an allowance run.
 *
 * Once the write has locked the allowance account row, it writes the marker
 * and holds that lock for the pause, so the test can start its own side
 * inside exactly that window. Prints `ok <journal no or run id> at
 * <microtime>` (when the write finished) and exits 0, or prints the
 * exception and exits 1.
 *
 * Its database comes from the environment the test passes (DB_DATABASE is
 * the test's own, which differs per parallel worker).
 */

use App\Models\Loan;
use App\Models\User;
use App\Services\Accounting\AccountMap;
use App\Services\Accounting\AllowancePoster;
use App\Services\Accounting\AutomaticPoster;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../../vendor/autoload.php';

$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $action, $marker, $pause] = $argv;
$arguments = array_slice($argv, 4);

$allowanceAccountId = (string) AccountMap::resolve()->accountFor('allowance_credit_losses');
$paused = false;

DB::listen(function (QueryExecuted $query) use (&$paused, $allowanceAccountId, $marker, $pause): void {
    $sql = strtolower($query->sql);

    if ($paused
        || ! str_starts_with($sql, 'select * from `accounting_accounts`')
        || ! str_contains($sql, 'for update')
        || (string) ($query->bindings[0] ?? '') !== $allowanceAccountId) {
        return;
    }

    $paused = true;
    file_put_contents($marker, 'locked');
    usleep((int) $pause);
});

try {
    if ($action === 'write-off') {
        [$newId, $sourceId, $userId] = $arguments;

        $result = DB::transaction(fn (): ?string => app(AutomaticPoster::class)->restructureRelease(
            Loan::query()->findOrFail((int) $newId),
            Loan::query()->findOrFail((int) $sourceId),
            (int) $userId,
            'cash',
        )['write_off']?->journal_no);
    } else {
        [$asOf, $expected, $userId] = $arguments;

        $result = app(AllowancePoster::class)->post($asOf, (int) $expected, User::query()->findOrFail((int) $userId))['run_id'];
    }

    fwrite(STDOUT, sprintf('ok %s at %.6F', $result, microtime(true)));
    exit(0);
} catch (Throwable $e) {
    // A refusal (409, 422) carries its message in the response it holds.
    $message = $e instanceof HttpResponseException ? (string) $e->getResponse()->getContent() : $e->getMessage();
    fwrite(STDOUT, $e::class.': '.$message);
    exit(1);
}
