<?php

namespace App\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Record that the owner reviewed scorecard 1.0 on 2026-11-11 and kept its
 * weights (35/25/15/10/10/5) and risk levels (owner answer, confirmed by
 * Arvin).
 *
 * Revisions are immutable, so the review is a new CreditScorecardReview row
 * beside revision 1.0; the revision row is never changed. The API's
 * `validated` then reads true for 1.0 and every score stamped with it
 * (CreditScorecardRevision::isChecked()). A revision saved later from
 * Scorecard Configuration carries other weights and is not reviewed.
 *
 * Revision 1.0 is marked only when it holds the reviewed weights; one that
 * doesn't is left alone and listed, since the review was of those weights.
 * One already reviewed or validated is passed over, and the unique
 * `revision_id` means a review written by another run in the meantime is
 * never doubled: that write is listed as skipped and logged, and a second run
 * changes nothing. The review writes one audit row on the revision as a
 * system change (no user, no address).
 *
 * Used by `credit-scoring:mark-scorecard-reviewed` (with `--dry-run` to
 * preview a database). The 2026_11_11_620200 migration does the same on
 * deploy, written out with the query builder so that it depends on no
 * application class; keep them in step (ScorecardOwnerReviewTest compares
 * them).
 */
class ScorecardOwnerReview
{
    public const VERSION = '1.0';

    public const REVIEWED_ON = '2026-11-11';

    public const REVIEWED_BY = 'The owner';

    public const NOTE = 'Weights (35/25/15/10/10/5) and risk levels reviewed and kept by the owner (owner answer relayed by Arvin, 2026-11-11).';

    /**
     * The reviewed weights, by category key.
     *
     * @var array<string, int>
     */
    public const WEIGHTS = ['repayment' => 35, 'capacity' => 25, 'exposure' => 15, 'stability' => 10, 'relationship' => 10, 'application' => 5];

    /**
     * Polymorphic rows store the full class name; there is no morph map.
     */
    private const AUDITABLE_TYPE = 'App\Models\CreditScorecardRevision';

    /**
     * Mark revision 1.0 reviewed, or with `$dryRun` only say whether it would be.
     *
     * @return array{marked: list<array{id: int, version: string, in_use: bool}>, skipped: list<array{id: int, version: string, reason: string}>}
     */
    public function run(bool $dryRun): array
    {
        $result = ['marked' => [], 'skipped' => []];

        $revision = DB::table('credit_scorecard_revisions')
            ->where('version', self::VERSION)
            ->first(['id', 'version', 'categories', 'validated']);

        if ($revision === null || (bool) $revision->validated || DB::table('credit_scorecard_reviews')->where('revision_id', $revision->id)->exists()) {
            return $result;
        }

        $entry = ['id' => (int) $revision->id, 'version' => (string) $revision->version];

        if (! self::holdsReviewedWeights($revision->categories)) {
            $result['skipped'][] = [...$entry, 'reason' => 'its weights are not the reviewed 35/25/15/10/10/5'];
            Log::warning('Scorecard 1.0 does not hold the reviewed weights; it was not marked reviewed.', $entry);

            return $result;
        }

        if ($dryRun || $this->write($revision)) {
            $inUse = (int) DB::table('credit_scoring_settings')->orderBy('id')->value('current_revision_id') === $entry['id'];
            $result['marked'][] = [...$entry, 'in_use' => $inUse];

            return $result;
        }

        $result['skipped'][] = [...$entry, 'reason' => 'reviewed by another run while the fix ran'];
        Log::warning('Scorecard 1.0 was reviewed by another run while the fix ran; that review was kept.', $entry);

        return $result;
    }

    /**
     * The one line the migration prints to the deploy log.
     *
     * @param  array{marked: list<mixed>, skipped: list<mixed>}  $result
     */
    public static function summary(array $result): string
    {
        return sprintf(
            'scorecard owner review: %d marked reviewed, %d left as they are',
            count($result['marked']),
            count($result['skipped']),
        );
    }

    /**
     * Whether the stored categories carry exactly the reviewed weights.
     */
    private static function holdsReviewedWeights(mixed $categories): bool
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

    /**
     * Write the review and its audit row together. A plain insert: only the
     * unique `revision_id` violation (another run wrote the review first) is
     * caught, which rolls both back and answers false so run() lists it as
     * skipped and logs it. Any other failure is thrown.
     */
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
}
