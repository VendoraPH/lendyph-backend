<?php

/**
 * Loan origination's history rows (notices, consents, notes and status
 * history) are never changed once written. Their models throw on update()
 * and delete(), but a bulk query skips the model, so this guard fails on any
 * statement under app/ that bulk-writes one of them, the way
 * CreditScoringImmutabilityArchTest guards the credit rows:
 * - through the model's query builder, its base query (`toBase()`) or
 *   DB::table();
 * - through a relation that returns one of them (`$application->notes()->delete()`),
 *   the relation names read from app/Models;
 * - through raw SQL (DB::update(), DB::delete(), DB::statement(),
 *   DB::affectingStatement(), DB::unprepared()) naming one of their tables.
 *
 * They go only with their application, through the retention erasure
 * (OriginationApplication::eraseForRetention()), and only
 * ApplicationTransitions opens a status write (OriginationApplication::withinTransition()).
 */
const IMMUTABLE_ORIGINATION_MODELS = ['OriginationNotice', 'OriginationConsent', 'OriginationNote', 'OriginationStatusHistory', 'OriginationAssessment'];

const IMMUTABLE_ORIGINATION_TABLES = ['origination_notices', 'origination_consents', 'origination_notes', 'origination_status_history', 'origination_assessments'];

/** @return list<string> */
function relationsToImmutableOriginationModels(): array
{
    $models = implode('|', IMMUTABLE_ORIGINATION_MODELS);
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
function bulkWritesToImmutableOriginationRows(string $source, array $relations = []): array
{
    $models = implode('|', IMMUTABLE_ORIGINATION_MODELS);
    $tables = implode('|', IMMUTABLE_ORIGINATION_TABLES);
    $relationNames = $relations === [] ? '' : '|->\\s*(?:'.implode('|', array_map('preg_quote', $relations)).')\\s*\\(\\s*\\)';
    $names = "/\\b(?:{$models})::(?!class\\b)|DB::table\\(\\s*['\"](?:{$tables})['\"]\\s*\\){$relationNames}/";
    $writes = '/->\s*(?:update|updateOrInsert|upsert|increment|decrement|incrementEach|decrementEach|delete|forceDelete|truncate|toBase)\s*\(/';
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

/** @return iterable<SplFileInfo> */
function originationAppFiles(): iterable
{
    $root = dirname(__DIR__, 2).'/app';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            yield $file;
        }
    }
}

it('reads the relations that return an origination history row', function () {
    expect(relationsToImmutableOriginationModels())->toContain('consents', 'notes', 'history');
});

it('catches bulk writes to the origination history rows, and lets the rest through', function (string $source, bool $offends) {
    expect(bulkWritesToImmutableOriginationRows($source, ['consents', 'notes', 'history']) !== [])->toBe($offends);
})->with([
    'relation delete' => ['$application->notes()->delete();', true],
    'relation update' => ["\$application->history()->update(['note' => 'x']);", true],
    'relation create' => ['$application->notes()->create($note);', false],
    'base query' => ["OriginationConsent::query()->toBase()->update(['accepted' => false]);", true],
    'base query read' => ['OriginationNotice::query()->where("type", "terms")->toBase()->get();', true],
    'raw delete' => ["DB::delete('delete from origination_consents where id = ?', [1]);", true],
    'raw update' => ["DB::update('update origination_status_history set note = ? where id = ?', ['x', 1]);", true],
    'raw statement' => ["DB::statement('TRUNCATE TABLE origination_notes');", true],
    'raw on a mutable table' => ["DB::update('update origination_links set visits = visits + 1');", false],
    'model query update' => ["OriginationNotice::query()->where('id', 1)->update(['body' => 'x']);", true],
    'multi-line chain' => ["OriginationStatusHistory::query()\n    ->whereKey(1)\n    ->delete();", true],
    'table update' => ["DB::table('origination_consents')->where('id', 1)->update(['accepted' => true]);", true],
    'table increment' => ["DB::table( \"origination_notes\" )->increment('id');", true],
    'create' => ['OriginationNote::create($attributes);', false],
    'class name only' => ['$model = OriginationNote::class; $link->delete();', false],
    'mutable model' => ["OriginationLink::query()->where('id', 1)->update(['active' => false]);", false],
]);

it('finds no bulk write to the origination history rows under app/', function () {
    $root = dirname(__DIR__, 2).'/app';
    $relations = relationsToImmutableOriginationModels();
    $offenders = [];

    foreach (originationAppFiles() as $file) {
        foreach (bulkWritesToImmutableOriginationRows(file_get_contents($file->getPathname()), $relations) as $statement) {
            $offenders[] = substr($file->getPathname(), strlen($root) + 1).': '.$statement;
        }
    }

    expect($offenders)->toBe([]);
});

it('opens a status write only in ApplicationTransitions', function () {
    $root = dirname(__DIR__, 2).'/app';
    $callers = [];

    foreach (originationAppFiles() as $file) {
        if (preg_match('/::withinTransition\s*\(/', (string) file_get_contents($file->getPathname()))) {
            $callers[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }

    expect($callers)->toBe(['Services/LoanOrigination/ApplicationTransitions.php']);
});
