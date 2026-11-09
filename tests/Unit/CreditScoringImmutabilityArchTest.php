<?php

/**
 * Credit scorecard revisions, credit scores, their factors and credit
 * decisions are never changed once written. Their models throw on update()
 * and delete(), but a bulk query skips the model, so this guard fails on any
 * statement under app/ that bulk-updates or deletes one of them, through the
 * model's query builder or through DB::table().
 *
 * A statement is the text between two semicolons. One that names an
 * immutable model (`CreditScore::`, not `CreditScoreMember::`) or table and
 * calls update(), delete(), forceDelete() or truncate() is an offender.
 */
const IMMUTABLE_CREDIT_MODELS = ['CreditScorecardRevision', 'CreditScore', 'CreditScoreFactor', 'CreditDecision'];

const IMMUTABLE_CREDIT_TABLES = ['credit_scorecard_revisions', 'credit_scores', 'credit_score_factors', 'credit_decisions'];

/**
 * @return list<string> the offending statements, whitespace collapsed
 */
function bulkWritesToImmutableCreditRows(string $source): array
{
    $models = implode('|', IMMUTABLE_CREDIT_MODELS);
    $tables = implode('|', IMMUTABLE_CREDIT_TABLES);
    $names = "/\\b(?:{$models})::|DB::table\\(\\s*['\"](?:{$tables})['\"]\\s*\\)/";
    $writes = '/->\s*(?:update|delete|forceDelete|truncate)\s*\(/';
    $offenders = [];

    foreach (explode(';', $source) as $statement) {
        if (preg_match($names, $statement) && preg_match($writes, $statement)) {
            $offenders[] = trim(preg_replace('/\s+/', ' ', $statement));
        }
    }

    return $offenders;
}

it('catches bulk writes to the immutable credit rows, and lets the rest through', function (string $source, bool $offends) {
    expect(bulkWritesToImmutableCreditRows($source) !== [])->toBe($offends);
})->with([
    'model query update' => ["CreditScore::query()->where('id', 1)->update(['score' => 1]);", true],
    'model where delete' => ['CreditDecision::where("borrower_id", 2)->delete();', true],
    'multi-line chain' => ["CreditScorecardRevision::query()\n    ->whereKey(1)\n    ->update(['validated' => true]);", true],
    'table update' => ["DB::table('credit_scores')->where('id', 1)->update(['score' => 1]);", true],
    'table truncate' => ['DB::table( "credit_score_factors" )->truncate();', true],
    'create' => ['CreditScore::create($attributes);', false],
    'similar model' => ["CreditScoreMember::query()->where('borrower_id', 1)->update(['checked_at' => now()]);", false],
    'dirty flags' => ["CreditScoreDirtyMember::query()->where('borrower_id', 1)->delete();", false],
    'mutable table' => ["DB::table('credit_policy_flags')->where('id', 1)->update(['cleared_at' => now()]);", false],
]);

it('finds no bulk update or delete of the immutable credit rows under app/', function () {
    $root = dirname(__DIR__, 2).'/app';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $offenders = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (bulkWritesToImmutableCreditRows(file_get_contents($file->getPathname())) as $statement) {
            $offenders[] = substr($file->getPathname(), strlen($root) + 1).': '.$statement;
        }
    }

    expect($offenders)->toBe([]);
});
