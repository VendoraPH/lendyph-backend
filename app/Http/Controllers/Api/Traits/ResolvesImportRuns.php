<?php

namespace App\Http\Controllers\Api\Traits;

use App\Models\CsvImportRun;
use App\Services\CsvImport\CsvImportUploadService;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A run by id, looked up only once the caller has been allowed to ask.
 *
 * NO ROUTE-MODEL BINDING ON `{run}`, on any of the five operator-surface
 * routes. `SubstituteBindings` runs before the controller action and before any
 * FormRequest, so a bound `CsvImportRun $run` parameter answers "does run #N
 * exist?" to a caller who is about to be refused anyway: 404 for an id nobody
 * has used, 403 for one somebody has. Walking the integers then yields the
 * cooperative's whole import history — how many migrations it has attempted and
 * roughly when — to any authenticated user, including the viewers, collectors
 * and loan officers that `imports:process` deliberately withholds it from.
 *
 * The fix is ordering, not concealment. Every action takes the id as a scalar,
 * calls `authorize('imports:process')` first, and resolves through here second,
 * so the lookup never happens for a caller who may not see the answer. An
 * authorised caller still gets an honest 404. The routes carry
 * `whereNumber('run')`, so a non-numeric id is not a route match at all and
 * nothing but digits ever reaches the cast.
 *
 * Delegates to CsvImportUploadService::findRun(), which is the same method the
 * three upload routes now use, so all eight import routes answer a missing run
 * with one body rather than two that agree until somebody edits one.
 */
trait ResolvesImportRuns
{
    protected function importRun(int $id): CsvImportRun
    {
        if (class_exists(CsvImportUploadService::class)) {
            return app(CsvImportUploadService::class)->findRun($id);
        }

        /*
         * Deliberately identical to CsvImportUploadService::findRun(), for as
         * long as that class is not on this branch yet — same status, same
         * message, so swapping to it changes no response and no test. DELETE
         * THIS BRANCH once the upload work has merged, exactly as
         * RunStatusReader::fallbackUploadBlock() is to be deleted.
         *
         * The body says only that the run was not found. Which of "no such id"
         * and "not yours" it means is not a distinction this feature has — a run
         * belongs to the deployment, not to a person — and spelling it out would
         * rebuild the oracle inside the response body.
         */
        $run = CsvImportRun::query()->find($id);

        if ($run === null) {
            throw new HttpResponseException(response()->json([
                'message' => "Import run #{$id} was not found.",
            ], 404));
        }

        return $run;
    }
}
