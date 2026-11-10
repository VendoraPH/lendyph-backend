<?php

/**
 * One side of WithdrawalRaceTest, run in its own PHP process so it is truly
 * concurrent with the withdrawal the test sends.
 *
 * Arguments: a marker file path, a pause in microseconds, the account id,
 * the user id and the amount in pesos.
 *
 * Once the withdrawal has locked the savings account row, it writes the
 * marker and holds that lock for the pause, so the test can send its own
 * withdrawal inside exactly that window. Prints `ok <reference> at
 * <microtime>` (when the withdrawal committed) and exits 0, or prints the
 * exception and exits 1.
 *
 * Its database comes from the environment the test passes.
 */

use App\Models\User;
use App\Services\Savings\Api\Teller;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../../../vendor/autoload.php';

$app = require __DIR__.'/../../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $marker, $pause, $accountId, $userId, $amount] = $argv;
$paused = false;

DB::listen(function (QueryExecuted $query) use (&$paused, $marker, $pause, $accountId): void {
    $sql = strtolower($query->sql);

    if ($paused
        || ! str_starts_with($sql, 'select * from `savings_accounts`')
        || ! str_contains($sql, 'for update')
        || (string) ($query->bindings[0] ?? '') !== (string) $accountId) {
        return;
    }

    $paused = true;
    file_put_contents($marker, 'locked');
    usleep((int) $pause);
});

try {
    $user = User::query()->findOrFail((int) $userId);
    $result = DB::transaction(fn () => app(Teller::class)->withdraw((int) $accountId, [
        'amount' => $amount,
        'date' => now()->toDateString(),
        'payment_method' => 'cash',
    ], $user));

    fwrite(STDOUT, sprintf('ok %s at %.6F', $result['transaction']?->reference ?? 'approval', microtime(true)));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, $e::class.': '.$e->getMessage());
    exit(1);
}
