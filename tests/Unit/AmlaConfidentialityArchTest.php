<?php

/**
 * AMLA's confidentiality and record keeping (AMLA design §3.6, §4.14, §4.17),
 * held in code so a later slice can't step around it:
 * - AMLA code never writes the main audit trail (no Auditable, AuditLog or
 *   AuditLogService), so nobody holding `audit_logs:view` is tipped off;
 * - AMLA never reaches the member portal, Linda, reminders, SMS, email or
 *   staff notices, and none of those name AMLA;
 * - AMLA code reads no environment (config/amla.php is the one place);
 * - every AMLA model is kept forever (AmlaKeptForever), and nothing under
 *   app/ or routes/ bulk-updates or deletes an AMLA table behind the models.
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

it('keeps AMLA out of the main audit trail, outbound channels, notices and the environment', function () use ($amlaFiles, $codeOf, $relative) {
    $forbidden = [
        'the main audit trail' => '/\bAuditable\b|\bAuditLog\b|\bAuditLogService\b|\baudit_logs\b/',
        'the member portal' => '/\\\\Portal\w*\\\\|\bPortal[A-Z]\w*|\bportal_\w+|\bauth:portal\b/',
        'Linda' => '/\bLinda\w*/',
        'reminders' => '/\bReminder\w*|\breminders?:/i',
        'SMS' => '/\bSms\w*|\bsemaphore\b|\btwilio\b/i',
        'mail' => '/\bMail::|Illuminate\\\\Support\\\\Facades\\\\Mail\b|\bMailable\b|(?<![\w>:$\\\\])mail\s*\(/i',
        'notifications' => '/\bNotification::|\bStaffNotification\b|\bStaffRecipients\b|->notify(?:Now)?\s*\(|Illuminate\\\\Notifications\\\\/',
        'the environment' => '/(?<![\w>:$\\\\])env\s*\(|\bgetenv\s*\(|\$_ENV\b|\$_SERVER\b/',
        'outbound calls' => '/\bHttp::|Illuminate\\\\Http\\\\Client\\\\|GuzzleHttp\\\\|\bcurl_\w+\s*\(/',
    ];
    $found = [];

    foreach ($amlaFiles() as $path) {
        $code = $codeOf($path);

        foreach ($forbidden as $what => $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $found[] = $relative($path).' uses '.$what;
            }
        }
    }

    expect($found)->toBe([]);
});

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

it('never bulk-updates or deletes an AMLA table behind the models', function () use ($filesMatching, $codeOf, $relative) {
    $offenders = [];

    foreach ($filesMatching(['app', 'routes'], '/\.php$/') as $path) {
        foreach (explode(';', $codeOf($path)) as $statement) {
            $namesTable = preg_match('/DB::table\s*\(\s*[\'"]amla_|\b(?:update|delete\s+from|truncate(?:\s+table)?|into)\s+`?amla_/i', $statement) === 1;
            $writes = preg_match('/->\s*(?:update|delete|forceDelete|truncate|upsert|increment|decrement|updateOrInsert)\s*\(|\bDB::(?:update|delete|statement|unprepared|affectingStatement)\s*\(/i', $statement) === 1;

            if ($namesTable && $writes) {
                $offenders[] = $relative($path).': '.trim(preg_replace('/\s+/', ' ', $statement));
            }
        }
    }

    expect($offenders)->toBe([]);
});
