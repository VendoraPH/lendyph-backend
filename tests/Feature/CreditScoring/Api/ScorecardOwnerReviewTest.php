<?php

use App\Models\AuditLog;
use App\Models\CreditScorecardReview;
use App\Models\CreditScorecardRevision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\CreditScoring\Api\CreditApiFixtures;
use Tests\TestCase;

/**
 * Owner answer (2026-11-11, confirmed by Arvin): the owner reviewed scorecard
 * 1.0's weights (35/25/15/10/10/5) and risk levels and kept them. Revisions
 * are immutable, so the review is its own append-only row
 * (`credit_scorecard_reviews`), written by a migration (and
 * `credit-scoring:mark-scorecard-reviewed`, with `--dry-run`) with one system
 * audit row. The API's `validated` then reads true for revision 1.0; a
 * revision saved later from Scorecard Configuration is not reviewed.
 */
uses(TestCase::class, CreditApiFixtures::class);

const OWNER_REVIEW_COMMAND = 'credit-scoring:mark-scorecard-reviewed';

const OWNER_REVIEW_MIGRATION = 'migrations/2026_11_11_620200_mark_scorecard_1_0_reviewed_by_owner.php';

beforeEach(function () {
    $this->pinToday();
    $this->revision = CreditScorecardRevision::query()->where('version', '1.0')->sole();
});

/**
 * Removes the review the migration wrote, as a deployment that hasn't run it
 * yet would look. Test-only: app code never deletes a review.
 */
function withoutOwnerReview(): void
{
    DB::table('credit_scorecard_reviews')->delete();
}

function ownerReviewMigration(): Migration
{
    return require database_path(OWNER_REVIEW_MIGRATION);
}

/**
 * @return array{reviews: list<array<string, mixed>>, audit_rows: int}
 */
function ownerReviewSnapshot(): array
{
    return [
        'reviews' => DB::table('credit_scorecard_reviews')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'audit_rows' => AuditLog::count(),
    ];
}

it('marks scorecard 1.0 reviewed by the owner on 2026-11-11 and keeps the revision row unchanged', function () {
    $review = CreditScorecardReview::query()->sole();

    expect($review->revision_id)->toBe($this->revision->id)
        ->and($review->reviewed_on->toDateString())->toBe('2026-11-11')
        ->and($review->reviewed_by)->toBe('The owner')
        ->and((bool) DB::table('credit_scorecard_revisions')->where('id', $this->revision->id)->value('validated'))->toBeFalse()
        ->and($this->revision->fresh()->isChecked())->toBeTrue();
});

it('no longer reports scorecard 1.0 as unvalidated on the config, a profile or a summary', function () {
    $admin = $this->headOffice();
    $member = $this->scoredMember();

    expect($this->actingAs($admin)->getJson('/api/credit-scoring/scorecard-config')->assertOk()->json('data.validated'))->toBeTrue()
        ->and($this->actingAs($admin)->getJson("/api/credit-scoring/borrowers/{$member->id}")->assertOk()->json('data.validated'))->toBeTrue();

    $summaryOnly = $this->caller(['credit_scoring:view', 'loans:view']);
    $summary = $this->actingAs($summaryOnly)->getJson("/api/credit-scoring/borrowers/{$member->id}")->assertOk()->json('data');
    expect($summary['details_hidden'])->toBeTrue()
        ->and($summary['validated'])->toBeTrue();
});

it('leaves a revision saved later from Scorecard Configuration unreviewed', function () {
    $admin = $this->caller(['credit_scoring:settings']);
    $config = $this->actingAs($admin)->getJson('/api/credit-scoring/scorecard-config')->assertOk()->json('data');
    $config['categories'] = array_map(fn (array $c) => $c['key'] === 'repayment' ? ['weight_percent' => 34] + $c : ($c['key'] === 'capacity' ? ['weight_percent' => 26] + $c : $c), $config['categories']);

    $saved = $this->actingAs($admin)->putJson('/api/credit-scoring/scorecard-config', $config)->assertOk()->json('data');

    expect($saved['version'])->toBe('1.1')
        ->and($saved['validated'])->toBeFalse()
        ->and(CreditScorecardRevision::query()->where('version', '1.1')->sole()->isChecked())->toBeFalse();
});

it('refuses to update or delete a review', function () {
    $review = CreditScorecardReview::query()->sole();

    expect(fn () => $review->update(['reviewed_by' => 'Someone else']))->toThrow(LogicException::class)
        ->and(fn () => $review->delete())->toThrow(LogicException::class)
        ->and(CreditScorecardReview::query()->sole()->reviewed_by)->toBe('The owner');
});

it('previews the review with --dry-run and writes nothing', function () {
    withoutOwnerReview();
    $before = ownerReviewSnapshot();

    expect(Artisan::call(OWNER_REVIEW_COMMAND, ['--dry-run' => true]))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('Dry run. Nothing is written.')
        ->toContain("Scorecard 1.0 (id {$this->revision->id}, in use): would be marked reviewed by the owner on 2026-11-11")
        ->toContain('Would mark 1 scorecard revision(s) reviewed; 0 left as they are.')
        ->and(ownerReviewSnapshot())->toBe($before);
});

it('writes the review with one system audit row, and a second run changes nothing', function () {
    withoutOwnerReview();
    $auditRows = AuditLog::count();

    Artisan::call(OWNER_REVIEW_COMMAND);
    expect(Artisan::output())->toContain("Scorecard 1.0 (id {$this->revision->id}, in use): marked reviewed by the owner on 2026-11-11")
        ->and(CreditScorecardReview::query()->sole()->revision_id)->toBe($this->revision->id)
        ->and(AuditLog::count())->toBe($auditRows + 1);

    $row = AuditLog::query()->latest('id')->firstOrFail();
    expect($row->user_id)->toBeNull()
        ->and($row->ip_address)->toBeNull()
        ->and($row->action)->toBe('scorecard_reviewed')
        ->and($row->auditable_type)->toBe((new CreditScorecardRevision)->getMorphClass())
        ->and((int) $row->auditable_id)->toBe($this->revision->id)
        ->and($row->old_values)->toBe(['validated' => false])
        ->and($row->new_values)->toEqual(['validated' => true, 'reviewed_on' => '2026-11-11', 'reviewed_by' => 'The owner'])
        ->and($row->description)->toBe('System change: Scorecard 1.0 reviewed by the owner on 2026-11-11; its weights (35/25/15/10/10/5) and risk levels are kept.');

    $after = ownerReviewSnapshot();
    Artisan::call(OWNER_REVIEW_COMMAND);
    expect(Artisan::output())->toContain('Marked 0 scorecard revision(s) reviewed; 0 left as they are.')
        ->and(ownerReviewSnapshot())->toBe($after);
});

it('skips and lists a revision 1.0 whose weights are not the reviewed ones', function () {
    withoutOwnerReview();
    $categories = json_decode((string) DB::table('credit_scorecard_revisions')->where('id', $this->revision->id)->value('categories'), true);
    $categories[0]['weight_percent'] = 30;
    $categories[1]['weight_percent'] = 30;
    DB::table('credit_scorecard_revisions')->where('id', $this->revision->id)->update(['categories' => json_encode($categories)]);
    $before = ownerReviewSnapshot();
    Log::spy();

    Artisan::call(OWNER_REVIEW_COMMAND);

    expect(Artisan::output())->toContain("Scorecard 1.0 (id {$this->revision->id}) left as it is: its weights are not the reviewed 35/25/15/10/10/5")
        ->toContain('Marked 0 scorecard revision(s) reviewed; 1 left as they are.')
        ->and(ownerReviewSnapshot())->toBe($before);
});

it('runs the same fix from the migration, safely twice, with the same outcome as the command', function () {
    withoutOwnerReview();
    $fromId = (int) AuditLog::max('id');
    $outcome = fn (): array => [
        'reviews' => DB::table('credit_scorecard_reviews')->get(['revision_id', 'reviewed_on', 'reviewed_by', 'note'])->map(fn ($row) => (array) $row)->all(),
        'audit_rows' => AuditLog::query()->where('id', '>', $fromId)->get()
            ->map(fn (AuditLog $row): array => $row->only(['user_id', 'action', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'ip_address', 'user_agent', 'description']))
            ->all(),
    ];

    DB::beginTransaction();
    Artisan::call(OWNER_REVIEW_COMMAND);
    $byCommand = $outcome();
    DB::rollBack();

    Log::spy();
    ownerReviewMigration()->up();
    Log::shouldHaveReceived('info')->withArgs(fn (string $message): bool => $message === 'scorecard owner review: 1 marked reviewed, 0 left as they are');
    $byMigration = $outcome();

    expect($byMigration)->toBe($byCommand)
        ->and($byMigration['reviews'])->toHaveCount(1);

    $after = ownerReviewSnapshot();
    ownerReviewMigration()->up();
    expect(ownerReviewSnapshot())->toBe($after);
});

it('keeps the migration free of application classes', function () {
    expect(file_get_contents(database_path(OWNER_REVIEW_MIGRATION)))->not->toMatch('/^use App\\\\/m');
});
