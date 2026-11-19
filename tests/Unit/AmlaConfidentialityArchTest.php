<?php

/**
 * AMLA's confidentiality and record keeping (AMLA design §3.6, §4.14, §4.17),
 * held in code so a later slice can't step around it:
 * - AMLA code never writes the main audit trail (no Auditable, AuditLog or
 *   AuditLogService), so nobody holding `audit_logs:view` is tipped off;
 * - AMLA never reaches the member portal, Linda, reminders, SMS, email or
 *   staff notices, and none of those name AMLA;
 * - AMLA code reads no environment (config/amla.php is the one place) and
 *   writes no application log;
 * - every AMLA model is kept forever (AmlaKeptForever), and nothing under
 *   app/, routes/ or database/ bulk-writes, drops or alters an AMLA table
 *   behind the models, or reaches under them through toBase() or getQuery().
 *   The one allowed use is AmlaKeptForever::performUpdate, the guarded
 *   single-row decision; the foundation migration's down() is the one drop.
 *
 * Each scan is a function, run against the real files and against a failing
 * fixture for every pattern, so a pattern that stops matching fails here.
 *
 * Every PHP file whose path names AMLA under app/, routes/ and database/ is
 * scanned, found recursively, so a new AMLA file is covered without being
 * listed here. The scan reads tokens, so comments don't count.
 */
$root = dirname(__DIR__, 2);

$relative = fn (string $path): string => substr($path, strlen($root) + 1);

/** @return list<string> PHP files under the folders whose relative path matches, sorted */
$filesMatching = function (array $folders, string $pattern) use ($root, $relative): array {
    $files = [];

    foreach ($folders as $folder) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$folder, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if ($file->isFile() && str_ends_with($path, '.php') && preg_match($pattern, $relative($path)) === 1) {
                $files[] = $path;
            }
        }
    }

    sort($files);

    return $files;
};

/** The file's code without its comments and docblocks. */
$codeOf = function (string $path): string {
    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
};

$amlaFiles = fn (): array => $filesMatching(['app', 'routes', 'database'], '/amla/i');

it('finds the AMLA files it guards', function () use ($amlaFiles, $relative) {
    $files = array_map($relative, $amlaFiles());

    expect($files)->toContain('app/Services/Amla/Foundation/AmlaLog.php')
        ->and($files)->toContain('app/Models/AmlaLogEntry.php')
        ->and($files)->toContain('routes/api/amla/foundation.php');
});

/** @var array<string, string> what AMLA code must never reach, by pattern on its code */
$channels = [
    'the main audit trail' => '/\bAuditable\b|\bAuditLog\b|\bAuditLogService\b|\baudit_logs\b/',
    'the member portal' => '/\\\\Portal\w*\\\\|\bPortal[A-Z]\w*|\bportal_\w+|\bauth:portal\b|\bguard\s*\(\s*[\'"]portal[\'"]|(?<![\w>:$\\\\])auth\s*\(\s*[\'"]portal[\'"]/',
    'Linda' => '/\blinda\w*/i',
    'reminders' => '/\bReminder\w*|\breminders?:/i',
    'SMS' => '/\bSms\w*|\bsemaphore\b|\btwilio\b/i',
    'mail' => '/\bMail::|Illuminate\\\\Support\\\\Facades\\\\Mail\b|\bMailable\b|\bMailer\b|Illuminate\\\\Contracts\\\\Mail\\\\|(?<![\w>:$\\\\])mail\s*\(/i',
    'notifications' => '/\bNotification::|\bStaffNotification\b|\bStaffRecipients\b|->notify(?:Now)?\s*\(|Illuminate\\\\Notifications\\\\|Services\\\\Notifications\b|\bNotifications::|\b\w+Notices\b/',
    'the application log' => '/\bLog::|Illuminate\\\\Support\\\\Facades\\\\Log\b|(?<![\w>:$\\\\])logger\s*\(|\bLoggerInterface\b/',
    'the environment' => '/(?<![\w>:$\\\\])env\s*\(|\bgetenv\s*\(|\$_ENV\b|\$_SERVER\b/',
    'outbound calls' => '/\bHttp::|Illuminate\\\\Http\\\\Client\\\\|GuzzleHttp\\\\|\bcurl_\w+\s*\(/',
];

/**
 * The code with its string literals taken out, for patterns that match a call
 * by name: "a covered transaction report (CTR)" is wording, not report().
 */
$withoutStrings = function (string $code): string {
    $source = str_starts_with(ltrim($code), '<?php') ? $code : '<?php '.$code;
    $kept = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $kept .= "''";

            continue;
        }

        $kept .= is_array($token) ? $token[1] : $token;
    }

    return $kept;
};

/** Laravel's global log helpers, which write the application log (AMLA-B1 re-review). */
$logHelpers = '/(?<![\w>:$\\\\])(?:info|report|report_if|report_unless)\s*\(/';

/** @return list<string> what the code reaches that AMLA must not */
$channelOffences = function (string $code) use ($channels, $withoutStrings, $logHelpers): array {
    $found = [];

    foreach ($channels as $what => $pattern) {
        if (preg_match($pattern, $code) === 1) {
            $found[] = $what;
        }
    }

    if (! in_array('the application log', $found, true) && preg_match($logHelpers, $withoutStrings($code)) === 1) {
        $found[] = 'the application log';
    }

    return $found;
};

/**
 * Statements (text between semicolons) that write an AMLA table behind its
 * model: a bulk write naming an AMLA model or table, a drop or rename through
 * Schema, raw DROP/ALTER/RENAME/TRUNCATE TABLE, or toBase()/getQuery() on an
 * AMLA model (anywhere in an AMLA file).
 *
 * @return list<string>
 */
$bulkWriteOffences = function (string $code, bool $isAmlaFile): array {
    $offences = [];
    $namesAmla = '/(?:DB::table|->table)\s*\(\s*[\'"]amla_|\bAmla[A-Z]\w*::|\b(?:update|delete\s+from|truncate(?:\s+table)?|into)\s+`?amla_/i';
    $writes = '/->\s*(?:update|delete|forceDelete|truncate|upsert|insertOrIgnore|increment|decrement|incrementEach|decrementEach|updateOrInsert|updateFrom)\s*\(|\bDB::(?:update|delete|statement|unprepared|affectingStatement)\s*\(/i';
    $underTheModel = '/->\s*(?:toBase|getQuery|getBaseQuery)\s*\(/';
    $schemaDrop = '/\bSchema::(?:drop\w*|rename)\s*\(\s*[\'"]amla_/i';
    $rawDdl = '/\b(?:drop|alter|rename|truncate)\s+table\s+(?:if\s+exists\s+)?`?amla_/i';
    $rawWrite = '/\b(?:update|delete\s+from)\s+`?amla_/i';

    foreach (explode(';', $code) as $statement) {
        $named = preg_match($namesAmla, $statement) === 1;
        $flat = trim((string) preg_replace('/\s+/', ' ', $statement));

        if (($named && preg_match($writes, $statement) === 1) || preg_match($rawWrite, $statement) === 1) {
            $offences[] = 'bulk write: '.$flat;
        }

        if (($named || $isAmlaFile) && preg_match($underTheModel, $statement) === 1) {
            $offences[] = 'under the model: '.$flat;
        }

        if (preg_match($schemaDrop, $statement) === 1 || preg_match($rawDdl, $statement) === 1) {
            $offences[] = 'drop or alter: '.$flat;
        }
    }

    /*
     * A Schema::table() block on an AMLA table that drops or renames a column,
     * matched across the whole block, since its closure spans several
     * statements.
     */
    preg_match_all('/\bSchema::table\s*\(\s*[\'"]amla_\w*[\'"]\s*,\s*(?:static\s+)?function\b.*?\n\s*}\s*\)/s', $code, $blocks);

    foreach ($blocks[0] as $block) {
        if (preg_match('/->\s*(?:dropColumn|dropColumns|renameColumn|dropMorphs|dropTimestamps|dropSoftDeletes|dropForeign|dropConstrainedForeignId)\s*\(/i', $block) === 1) {
            $offences[] = 'drop or alter: '.trim((string) preg_replace('/\s+/', ' ', $block));
        }
    }

    return $offences;
};

/** The one place allowed under the model: AmlaKeptForever::performUpdate, taken out before the scan. */
$withoutAllowedUse = function (string $file, string $code): string {
    if ($file !== 'app/Traits/AmlaKeptForever.php') {
        return $code;
    }

    return (string) preg_replace('/protected function performUpdate\(.*?\n    }\n/s', '', $code, 1);
};

/**
 * The AMLA migrations that create tables: each down() drops only the tables it
 * made, and refuses once they hold rows. Nothing else may drop or alter one.
 */
const AMLA_TABLE_MIGRATIONS = [
    'database/migrations/2026_11_19_210000_create_amla_foundation_tables.php',
    'database/migrations/2026_11_20_100000_create_amla_cdd_tables.php',
];

it('keeps AMLA out of the main audit trail, outbound channels, notices, logs and the environment', function () use ($amlaFiles, $codeOf, $relative, $channelOffences) {
    $found = [];

    foreach ($amlaFiles() as $path) {
        foreach ($channelOffences($codeOf($path)) as $what) {
            $found[] = $relative($path).' uses '.$what;
        }
    }

    expect($found)->toBe([]);
});

it('catches every channel pattern', function (string $code, string $what) use ($channelOffences) {
    expect($channelOffences($code))->toContain($what);
})->with([
    'audit trail' => ['AuditLog::query()->create([]);', 'the main audit trail'],
    'portal namespace' => ['use App\Services\Portal\PortalNotices;', 'the member portal'],
    'portal guard' => ["Auth::guard('portal')->user();", 'the member portal'],
    'portal auth helper' => ["auth('portal')->id();", 'the member portal'],
    'Linda class' => ['LindaAnswer::make();', 'Linda'],
    'linda table' => ["DB::table('linda_questions')->count();", 'Linda'],
    'reminders' => ['ReminderDispatcher::send();', 'reminders'],
    'SMS' => ['SmsDriver::send();', 'SMS'],
    'mail facade' => ['Mail::raw("x", fn () => null);', 'mail'],
    'mailer contract' => ['use Illuminate\Contracts\Mail\Mailer;', 'mail'],
    'mailer injected' => ['public function __construct(private Mailer $mailer) {}', 'mail'],
    'staff notifications service' => ['use App\Services\Notifications;', 'notifications'],
    'staff notifications call' => ['Notifications::loanApproved($loan);', 'notifications'],
    'savings notices' => ['app(AccountEventNotices::class)->frozen($account);', 'notifications'],
    'CDA notices' => ['app(WorkflowNotices::class)->approved($report);', 'notifications'],
    'notify' => ['$user->notify($notice);', 'notifications'],
    'log facade' => ['Log::info("case", ["str" => $id]);', 'the application log'],
    'logger helper' => ['logger("case opened");', 'the application log'],
    'logger chained' => ['logger()->warning("x");', 'the application log'],
    'info helper' => ['info("case opened", ["id" => $id]);', 'the application log'],
    'report helper' => ['report($exception);', 'the application log'],
    'report_if helper' => ['report_if($failed, $exception);', 'the application log'],
    'env' => ["env('AMLA_MONITORING_ENABLED');", 'the environment'],
    'http' => ['Http::post("https://x.test");', 'outbound calls'],
]);

it('keeps AMLA out of the portal, Linda, reminders and staff notices', function () use ($filesMatching, $codeOf, $relative) {
    $found = [];

    foreach ($filesMatching(['app', 'routes'], '/portal|linda|reminder|sms|notification|notice/i') as $path) {
        if (preg_match('/\bAmla\w*|\bamla[_:]\w*/i', $codeOf($path)) === 1) {
            $found[] = $relative($path);
        }
    }

    expect($found)->toBe([]);
});

it('keeps every AMLA model forever', function () use ($root, $relative) {
    $models = glob($root.'/app/Models/Amla*.php') ?: [];
    $missing = [];

    foreach ($models as $path) {
        if (preg_match('/^\s*use\s+[^;]*\bAmlaKeptForever\b[^;]*;/m', (string) file_get_contents($path)) !== 1) {
            $missing[] = $relative($path);
        }
    }

    expect($models)->not->toBeEmpty()
        ->and($missing)->toBe([]);
});

it('never writes, drops or alters an AMLA table behind the models', function () use ($filesMatching, $codeOf, $relative, $bulkWriteOffences, $withoutAllowedUse) {
    $offenders = [];
    $scanned = 0;

    foreach ($filesMatching(['app', 'routes', 'database'], '/\.php$/') as $path) {
        $file = $relative($path);

        if (in_array($file, AMLA_TABLE_MIGRATIONS, true)) {
            continue;
        }

        $scanned++;
        $isAmlaFile = preg_match('/amla/i', $file) === 1;

        foreach ($bulkWriteOffences($withoutAllowedUse($file, $codeOf($path)), $isAmlaFile) as $offence) {
            $offenders[] = "{$file}: {$offence}";
        }
    }

    expect($scanned)->toBeGreaterThan(100)
        ->and($offenders)->toBe([]);
});

it('still guards the trait outside its one allowed method', function () use ($root, $codeOf, $bulkWriteOffences, $withoutAllowedUse) {
    $file = 'app/Traits/AmlaKeptForever.php';
    $code = $codeOf($root.'/'.$file);

    expect($bulkWriteOffences($code, true))->not->toBe([])
        ->and($bulkWriteOffences($withoutAllowedUse($file, $code), true))->toBe([])
        ->and($bulkWriteOffences($withoutAllowedUse($file, $code.'function sneaky() { return $this->newQuery()->toBase(); }'), true))->not->toBe([]);
});

it('catches every bulk-write pattern', function (string $code, bool $isAmlaFile) use ($bulkWriteOffences) {
    expect($bulkWriteOffences($code, $isAmlaFile))->not->toBe([]);
})->with([
    'model update' => ["AmlaLogEntry::query()->where('id', 1)->update(['action' => 'x']);", false],
    'model delete' => ['AmlaChangeRequest::whereKey(1)->delete();', false],
    'DB table update' => ["DB::table('amla_log_entries')->update(['action' => 'x']);", false],
    'DB table delete' => ["DB::table('amla_log_entries')->where('id', 1)->delete();", false],
    'named connection' => ["DB::connection('mysql')->table('amla_log_entries')->delete();", false],
    'raw update' => ["DB::update('update amla_log_entries set action = ?', ['x']);", false],
    'raw delete' => ["DB::delete('delete from amla_settings_versions');", false],
    'toBase on a model' => ["AmlaLogEntry::query()->toBase()->update(['action' => 'x']);", false],
    'getQuery on a model' => ['AmlaChangeRequest::query()->getQuery()->delete();', false],
    'toBase in an AMLA file' => ['$query->toBase()->update($values);', true],
    'getBaseQuery in an AMLA file' => ['$builder->getBaseQuery()->delete();', true],
    'Schema drop' => ["Schema::drop('amla_log_entries');", false],
    'Schema dropIfExists' => ["Schema::dropIfExists('amla_change_requests');", false],
    'Schema rename' => ["Schema::rename('amla_log_entries', 'old');", false],
    'raw drop' => ["DB::statement('DROP TABLE amla_log_entries');", false],
    'raw drop if exists' => ["DB::unprepared('drop table if exists `amla_calendar_years`');", false],
    'raw alter' => ["DB::statement('ALTER TABLE amla_log_entries DROP COLUMN details');", false],
    'raw truncate' => ["DB::statement('TRUNCATE TABLE amla_log_entries');", false],
    'raw update on a named connection' => ["DB::connection('mysql')->statement('UPDATE amla_profiles SET occupation = null');", false],
    'raw delete through select' => ["DB::select('delete from amla_risk_assessments where id = 1');", false],
    'raw update through a PDO' => ["DB::getPdo()->exec('update `amla_reviews` set notes = null');", false],
    'raw delete through affectingStatement' => ["DB::connection()->affectingStatement('DELETE FROM amla_id_verifications');", false],
    'Schema table dropColumn' => ["Schema::table('amla_profiles', function (Blueprint \$table) {\n    \$table->string('x')->nullable();\n    \$table->dropColumn('pep_details');\n});", false],
    'Schema table renameColumn' => ["Schema::table('amla_risk_assessments', static function (Blueprint \$table) {\n    \$table->renameColumn('level', 'old_level');\n});", false],
    'new table update' => ["AmlaProfile::query()->update(['occupation' => null]);", false],
    'new table delete' => ["DB::table('amla_profile_changes')->delete();", false],
    'new table drop' => ["Schema::dropIfExists('amla_risk_rule_versions');", false],
]);

it('lets an added column through', function () use ($bulkWriteOffences) {
    expect($bulkWriteOffences("Schema::table('amla_profiles', function (Blueprint \$table) {\n    \$table->string('employer')->nullable();\n});", false))->toBe([]);
});

it('keeps wording about reports out of the log-helper scan', function () use ($channelOffences) {
    expect($channelOffences("'words' => 'Covered transaction report (CTR)', 'info' => 'x',"))->toBe([]);
});

it('lets reads and inserts through', function () use ($bulkWriteOffences) {
    expect($bulkWriteOffences("AmlaLogEntry::query()->create(['action' => 'log_viewed']); DB::table('amla_log_entries')->where('id', 1)->exists(); AmlaSettingsVersion::query()->orderByDesc('version')->lockForUpdate()->first();", false))->toBe([]);
});
