<?php

/**
 * One member transfer, in its own PHP process, by MemberTransferLockTest, so
 * it can be truly concurrent with a loan application the test opens in its
 * own process.
 *
 * Arguments: the member id, the destination branch id, a marker file path
 * and a pause in microseconds. Once the member's row is updated (not yet
 * committed), it writes the marker and holds its transaction open for the
 * pause. Prints `ok at <microtime>` (when the transfer committed) and exits
 * 0, or prints the exception and exits 1.
 *
 * Its database comes from the environment the test passes.
 */

use App\Models\Borrower;
use App\Services\Branches\MemberTransfer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $memberId, $branchId, $marker, $pause] = $argv;

$paused = false;

DB::listen(function (QueryExecuted $query) use (&$paused, $marker, $pause): void {
    if ($paused || ! str_starts_with(strtolower($query->sql), 'update `borrowers`')) {
        return;
    }

    $paused = true;
    file_put_contents($marker, 'moved');
    usleep((int) $pause);
});

try {
    app(MemberTransfer::class)->transfer(Borrower::query()->findOrFail((int) $memberId), (int) $branchId, 'Lock test');

    fwrite(STDOUT, sprintf('ok at %.6F', microtime(true)));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, $e::class.': '.$e->getMessage());
    exit(1);
}
