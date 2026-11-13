<?php

use App\Services\Cda\Registers\TrainingDocumentRefiler;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * A CDA training's documents follow the training (follow-ups batch,
 * 2026-11-14): on each deployment, the documents not linked to a report of
 * every training saved with them in another period move to the training's
 * own period, the one its `documents_count` reads.
 *
 * Runs TrainingDocumentRefiler::run() itself, the same code as
 * `cda:refile-training-documents`, so `php artisan
 * cda:refile-training-documents --dry-run` lists on a database exactly what
 * this will change there, writing nothing. Each move is re-checked under the
 * training's and the document's locks and writes one audit row as a system
 * change (no user, no address). A training with no completed or started date,
 * or a document that changed while it ran, is left as it is and logged. A
 * second run changes nothing.
 *
 * down() does nothing: a move is not undone automatically, since the period
 * it left is the wrong one. Audit rows are never removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $result = app(TrainingDocumentRefiler::class)->run(false);
        $summary = sprintf(
            'CDA training documents: %d moved to their training\'s period, %d left as they are',
            count($result['moved']),
            count($result['skipped']),
        );

        Log::info($summary, $result);

        foreach ($result['skipped'] as $skip) {
            Log::warning("CDA training #{$skip['training_id']} documents left as they are: {$skip['reason']}.");
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    public function down(): void {}
};
