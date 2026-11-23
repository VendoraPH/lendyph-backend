<?php

/**
 * One loan application opened for a member, in its own PHP process, by
 * MemberTransferLockTest, so it can be truly concurrent with the member
 * transfer the test runs in its own process.
 *
 * Arguments: the member id, the loan product id, the staff user id, a marker
 * file path and a pause in microseconds. Once the loan row is written (not yet
 * committed), it writes the marker and holds its transaction open for the
 * pause, so the test can start the transfer inside exactly that window.
 * Prints `ok <loan id> at <microtime>` (when the create committed) and exits
 * 0, or prints the exception and exits 1.
 *
 * Its database comes from the environment the test passes.
 */

use App\Models\User;
use App\Services\LoanService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $memberId, $productId, $userId, $marker, $pause] = $argv;

$paused = false;

DB::listen(function (QueryExecuted $query) use (&$paused, $marker, $pause): void {
    if ($paused || ! str_starts_with(strtolower($query->sql), 'insert into `loans`')) {
        return;
    }

    $paused = true;
    file_put_contents($marker, 'opened');
    usleep((int) $pause);
});

try {
    $loan = app(LoanService::class)->createLoan([
        'borrower_id' => (int) $memberId,
        'loan_product_id' => (int) $productId,
        'principal_amount' => 10000,
        'start_date' => now()->toDateString(),
    ], User::query()->findOrFail((int) $userId));

    fwrite(STDOUT, sprintf('ok %d at %.6F', $loan->id, microtime(true)));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, $e::class.': '.$e->getMessage());
    exit(1);
}
