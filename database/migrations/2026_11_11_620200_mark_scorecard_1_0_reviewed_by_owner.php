<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * The owner reviewed scorecard 1.0's weights (35/25/15/10/10/5) and risk
 * levels and kept them (owner answer, confirmed by Arvin, 2026-11-11).
 *
 * Revisions are immutable, so the review goes in a new append-only table,
 * `credit_scorecard_reviews`, one row per revision (unique `revision_id`),
 * restricting the revision's delete as scores do. It has no timestamps: the
 * business date is `reviewed_on` (a DATE, which TimezoneShift never lists),
 * and the time of writing is the audit row's.
 *
 * Then revision 1.0 is marked reviewed, with the same checks, skips and audit
 * row as App\Services\ScorecardOwnerReview, whose docblock
 * explains them, written here with the query builder alone and spelled out so
 * this writes on its last deployment exactly what it wrote on its first.
 * ScorecardOwnerReviewTest holds the two to the same outcome.
 * `php artisan credit-scoring:mark-scorecard-reviewed --dry-run` shows what
 * this will change on a database before it is deployed there. Every step is
 * guarded, so it runs safely twice.
 */
return new class extends Migration
{
    private const VERSION = '1.0';

    private const REVIEWED_ON = '2026-11-11';

    private const REVIEWED_BY = 'The owner';

    private const NOTE = 'Weights (35/25/15/10/10/5) and risk levels reviewed and kept by the owner (owner answer relayed by Arvin, 2026-11-11).';

    private const WEIGHTS = ['repayment' => 35, 'capacity' => 25, 'exposure' => 15, 'stability' => 10, 'relationship' => 10, 'application' => 5];

    private const AUDITABLE_TYPE = 'App\Models\CreditScorecardRevision';

    public function up(): void
    {
        if (! Schema::hasTable('credit_scorecard_reviews')) {
            Schema::create('credit_scorecard_reviews', function (Blueprint $table) {
                $table->id();
                $table->foreignId('revision_id')->unique()->constrained('credit_scorecard_revisions')->restrictOnDelete();
                $table->date('reviewed_on');
                $table->string('reviewed_by', 100);
                $table->string('note', 500)->nullable();
            });
        }

        $result = $this->markReviewed();

        $summary = sprintf(
            'scorecard owner review: %d marked reviewed, %d left as they are',
            count($result['marked']),
            count($result['skipped']),
        );

        Log::info($summary, $result);

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            (new ConsoleOutput)->writeln(PHP_EOL."  {$summary}");
        }
    }

    /**
     * Drops the table only while it holds no review: a recorded review is
     * kept, as its audit row is.
     */
    public function down(): void
    {
        if (Schema::hasTable('credit_scorecard_reviews') && ! DB::table('credit_scorecard_reviews')->exists()) {
            Schema::drop('credit_scorecard_reviews');
        }
    }

    /**
     * @return array{marked: list<array{id: int, version: string}>, skipped: list<array{id: int, version: string, reason: string}>}
     */
    private function markReviewed(): array
    {
        $result = ['marked' => [], 'skipped' => []];

        $revision = DB::table('credit_scorecard_revisions')
            ->where('version', self::VERSION)
            ->first(['id', 'version', 'categories', 'validated']);

        if ($revision === null || (bool) $revision->validated || DB::table('credit_scorecard_reviews')->where('revision_id', $revision->id)->exists()) {
            return $result;
        }

        $entry = ['id' => (int) $revision->id, 'version' => (string) $revision->version];

        if (! $this->holdsReviewedWeights($revision->categories)) {
            $result['skipped'][] = [...$entry, 'reason' => 'its weights are not the reviewed 35/25/15/10/10/5'];
            Log::warning('Scorecard 1.0 does not hold the reviewed weights; it was not marked reviewed.', $entry);

            return $result;
        }

        if ($this->write($revision)) {
            $result['marked'][] = $entry;

            return $result;
        }

        $result['skipped'][] = [...$entry, 'reason' => 'reviewed by another run while the fix ran'];
        Log::warning('Scorecard 1.0 was reviewed by another run while the fix ran; that review was kept.', $entry);

        return $result;
    }

    private function holdsReviewedWeights(mixed $categories): bool
    {
        $categories = json_decode((string) $categories, true);

        if (! is_array($categories)) {
            return false;
        }

        $weights = [];

        foreach ($categories as $category) {
            if (! is_array($category) || ! is_numeric($category['weight_percent'] ?? null)) {
                return false;
            }

            $weights[(string) ($category['key'] ?? '')] = (float) $category['weight_percent'];
        }

        ksort($weights);
        $reviewed = array_map('floatval', self::WEIGHTS);
        ksort($reviewed);

        return $weights === $reviewed;
    }

    private function write(object $revision): bool
    {
        try {
            DB::transaction(function () use ($revision): void {
                DB::table('credit_scorecard_reviews')->insert([
                    'revision_id' => $revision->id,
                    'reviewed_on' => self::REVIEWED_ON,
                    'reviewed_by' => self::REVIEWED_BY,
                    'note' => self::NOTE,
                ]);

                DB::table('audit_logs')->insert([
                    'user_id' => null,
                    'action' => 'scorecard_reviewed',
                    'auditable_type' => self::AUDITABLE_TYPE,
                    'auditable_id' => $revision->id,
                    'old_values' => json_encode(['validated' => false]),
                    'new_values' => json_encode(['validated' => true, 'reviewed_on' => self::REVIEWED_ON, 'reviewed_by' => self::REVIEWED_BY]),
                    'ip_address' => null,
                    'user_agent' => null,
                    'description' => 'System change: Scorecard 1.0 reviewed by the owner on 2026-11-11; its weights (35/25/15/10/10/5) and risk levels are kept.',
                    'created_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
};
