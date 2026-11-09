<?php

/**
 * Credit scorecard revisions, credit scores, their factors and credit
 * decisions are never changed once written. Their models throw on update()
 * and delete(), but a bulk query skips the model, so this guard fails on any
 * statement under app/ that bulk-updates or deletes one of them:
 * - through the model's query builder or DB::table();
 * - through a relation that returns one of them (`$score->factors()->delete()`),
 *   the relation names read from app/Models;
 * - through raw SQL (DB::update(), DB::delete(), DB::statement(),
 *   DB::affectingStatement(), DB::unprepared()) naming one of their tables.
 *
 * A statement is the text between two semicolons. One that names an
 * immutable model (`CreditScore::`, not `CreditScoreMember::`), table or
 * relation and calls update(), delete(), forceDelete() or truncate() is an
 * offender, and so is raw SQL that names an immutable table.
 */
const IMMUTABLE_CREDIT_MODELS = ['CreditScorecardRevision', 'CreditScore', 'CreditScoreFactor', 'CreditDecision'];

const IMMUTABLE_CREDIT_TABLES = ['credit_scorecard_revisions', 'credit_scores', 'credit_score_factors', 'credit_decisions'];

/**
 * The names of the relations under app/Models that return an immutable
 * credit model.
 *
 * @return list<string>
 */
function relationsToImmutableCreditModels(): array
{
    $models = implode('|', IMMUTABLE_CREDIT_MODELS);
    $relation = "/function\\s+(\\w+)\\s*\\([^)]*\\)[^{]*\\{[^}]*?\\\$this->(?:hasMany|hasOne|belongsTo|belongsToMany|hasManyThrough|hasOneThrough|morphMany|morphOne)\\(\\s*(?:{$models})::class/s";
    $names = [];

    foreach (glob(dirname(__DIR__, 2).'/app/Models/*.php') ?: [] as $file) {
        preg_match_all($relation, (string) file_get_contents($file), $matches);
        array_push($names, ...$matches[1]);
    }

    return array_values(array_unique($names));
}

/**
 * @param  list<string>  $relations
 * @return list<string> the offending statements, whitespace collapsed
 */
function bulkWritesToImmutableCreditRows(string $source, array $relations = []): array
{
    $models = implode('|', IMMUTABLE_CREDIT_MODELS);
    $tables = implode('|', IMMUTABLE_CREDIT_TABLES);
    $relationNames = $relations === [] ? '' : '|->\\s*(?:'.implode('|', array_map('preg_quote', $relations)).')\\s*\\(\\s*\\)';
    $names = "/\\b(?:{$models})::|DB::table\\(\\s*['\"](?:{$tables})['\"]\\s*\\){$relationNames}/";
    $writes = '/->\s*(?:update|delete|forceDelete|truncate)\s*\(/';
    $rawSql = '/\bDB::(?:update|delete|statement|affectingStatement|unprepared)\s*\(/';
    $rawTable = "/\\b(?:{$tables})\\b/";
    $offenders = [];

    foreach (explode(';', $source) as $statement) {
        $bulk = preg_match($names, $statement) && preg_match($writes, $statement);
        $raw = preg_match($rawSql, $statement) && preg_match($rawTable, $statement);

        if ($bulk || $raw) {
            $offenders[] = trim(preg_replace('/\s+/', ' ', $statement));
        }
    }

    return $offenders;
}

it('reads the relations that return an immutable credit model', function () {
    expect(relationsToImmutableCreditModels())->toContain('factors', 'revision', 'currentRevision', 'creditScore');
});

it('catches bulk writes to the immutable credit rows, and lets the rest through', function (string $source, bool $offends) {
    expect(bulkWritesToImmutableCreditRows($source, ['factors', 'revision', 'creditScore']) !== [])->toBe($offends);
})->with([
    'relation delete' => ['$score->factors()->delete();', true],
    'relation update' => ["\$decision->creditScore()->update(['score' => 1]);", true],
    'relation create' => ['$score->factors()->create($factor);', false],
    'raw delete' => ["DB::delete('delete from credit_scores where id = ?', [1]);", true],
    'raw update' => ["DB::update('update credit_decisions set reason = ? where id = ?', ['x', 1]);", true],
    'raw statement' => ["DB::statement('TRUNCATE TABLE credit_score_factors');", true],
    'raw on a mutable table' => ["DB::update('update credit_score_members set trend = ?', ['flat']);", false],
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
    $relations = relationsToImmutableCreditModels();
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $offenders = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (bulkWritesToImmutableCreditRows(file_get_contents($file->getPathname()), $relations) as $statement) {
            $offenders[] = substr($file->getPathname(), strlen($root) + 1).': '.$statement;
        }
    }

    expect($offenders)->toBe([]);
});
