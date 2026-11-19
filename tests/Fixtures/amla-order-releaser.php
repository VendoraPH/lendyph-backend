<?php

/**
 * Approves one order's release in its own PHP process, for
 * AmlaOrderReleaseRaceTest, so a payout into savings can commit in the middle
 * of it.
 *
 * Arguments: the order id, the approving user's id, a marker file path and a
 * go file path. Right after the release's first, plain read of the order's
 * hold links (which fixes the transaction's view of the database), it writes
 * the marker and waits until the go file exists, so the test can commit a
 * payout that links a new hold in exactly that window. Prints `ok` and exits
 * 0, or prints the exception and exits 1.
 *
 * Its database comes from the environment the test passes.
 */

use App\Models\User;
use App\Services\Amla\Screening\Orders;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $orderId, $checkerId, $marker, $go] = $argv;

$paused = false;

DB::listen(function (QueryExecuted $query) use (&$paused, $marker, $go): void {
    $sql = strtolower($query->sql);

    if ($paused || ! str_contains($sql, 'from `amla_order_holds`') || str_contains($sql, 'for update')) {
        return;
    }

    $paused = true;
    file_put_contents($marker, 'read');
    $deadline = microtime(true) + 30;

    while (! file_exists($go) && microtime(true) < $deadline) {
        usleep(20_000);
    }
});

try {
    app(Orders::class)->approveReleaseLocked(User::query()->findOrFail((int) $checkerId), (int) $orderId, null);

    fwrite(STDOUT, 'ok');
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, $e::class.': '.$e->getMessage());
    exit(1);
}
