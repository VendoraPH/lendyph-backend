<?php

/**
 * Linda belongs to the one deployment it runs in (Arvin, 2026-11-16): each
 * domain's Linda answers only from that deployment's own database and keeps
 * nothing anywhere another deployment could read.
 *
 * The deployments are already separate (their own API, database, cache and
 * files, chosen by each box's .env). This keeps Linda's code from stepping
 * around that: it never names a database connection, another schema or a
 * cache store, never builds its own connection, never touches Redis, files or
 * a disk, never reads the environment directly, never runs a process, and
 * only the model driver (AnthropicModel and its LindaHttpGuard) may call out,
 * sending the question text and nothing else (LindaPrompt and its tests).
 *
 * Every PHP file whose path names Linda under app/, routes/ and database/ is
 * scanned, found recursively, so a new Linda file is covered without being
 * listed here. config/linda.php is the one place that reads env().
 *
 * The scan reads tokens, not text, so comments and docblocks that name a call
 * don't count.
 */
$root = dirname(__DIR__, 2);

$relative = fn (string $path): string => substr($path, strlen($root) + 1);

/** @return list<string> every Linda PHP file, absolute, sorted */
$lindaSources = function () use ($root, $relative): array {
    $files = [];

    foreach (['app', 'routes', 'database'] as $folder) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$folder, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if ($file->isFile() && str_ends_with($path, '.php') && preg_match('/linda/i', $relative($path)) === 1) {
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

/** The file's string literals (and string parts), one per line, for raw SQL. */
$stringsOf = function (string $path): string {
    $strings = [];

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $strings[] = $token[1];
        }
    }

    return implode("\n", $strings);
};

/** A schema-qualified table name inside quotes: 'other_db.loans', "`x`.`y`". */
$qualified = '[\'"]\s*`?[A-Za-z_]\w*`?\s*\.\s*`?[A-Za-z_]\w*`?\s*(?:\s+as\s+\w+\s*)?[\'"]';

$forbidden = [
    'a named database connection' => '/\b\w+::connection\s*\(|->connection\s*\(|::on\s*\(|->setConnection\s*\(|\$connection\s*=|\bgetConnectionName\b|#\[\s*\\\\?(?:[\w\\\\]*\\\\)?Connection\s*\(/i',
    'a connection of its own' => '/\bDB::(?:connectUsing|build)\s*\(|\bnew\s+\\\\?PDO\b|\bPDO::connect\s*\(/i',
    'a schema-qualified table' => '/#\[\s*\\\\?(?:[\w\\\\]*\\\\)?Table\s*\(\s*(?:name\s*:\s*)?'.$qualified.'|\$table\s*=\s*'.$qualified.'|\bDB::table\s*\(\s*'.$qualified.'|->(?:from|table|join|leftJoin|rightJoin|crossJoin|joinWhere|leftJoinWhere|rightJoinWhere)\s*\(\s*'.$qualified.'/i',
    'a named cache store' => '/\bCache::(?:store|driver|memo)\s*\(|\bcache\s*\(\s*\)\s*->\s*(?:store|driver|memo)\s*\(|\b(?:app|resolve)\s*\(\s*[\'"]cache(?:\.store)?[\'"]\s*\)/i',
    'Redis' => '/\bRedis::|Illuminate\\\\Support\\\\Facades\\\\Redis\b|Illuminate\\\\Redis\\\\|\b(?:app|resolve)\s*\(\s*[\'"]redis[\'"]\s*\)|\bnew\s+\\\\?Redis\b/i',
    'files or a disk' => '/\bStorage::|->disk\s*\(|\bFile::|Illuminate\\\\Support\\\\Facades\\\\(?:Storage|File)\b|\b(?:file_put_contents|fopen|fwrite|tmpfile|tempnam|unlink|mkdir|touch)\s*\(/i',
    'the environment' => '/(?<![\w>:$\\\\])env\s*\(|\bEnv::|Illuminate\\\\Support\\\\Env\b|\bgetenv\s*\(|\bputenv\s*\(|\$_ENV\b/i',
    'a process' => '/(?<![\w>:$\\\\])(?<!function\s)(?:exec|shell_exec|proc_open|popen|passthru|system|pcntl_exec)\s*\(|\bProcess::|Illuminate\\\\Support\\\\Facades\\\\Process\b|Symfony\\\\Component\\\\Process\\\\/i',
];

/** Raw SQL in a string naming a schema-qualified table: "select * from other_db.loans". */
$rawQualified = '/\b(?:from|join|into|update|table)\s+`?[A-Za-z_]\w*`?\s*\.\s*`?[A-Za-z_]\w*/i';

/** Calls out of the server: only the model driver may. */
$outbound = '/\bHttp::|Illuminate\\\\Support\\\\Facades\\\\Http\b|Illuminate\\\\Http\\\\Client\\\\|\bPendingRequest\b|GuzzleHttp\\\\|Symfony\\\\(?:Contracts|Component)\\\\HttpClient\\\\|\bHttpClient::create\s*\(|Psr\\\\Http\\\\Client\\\\|\bcurl_\w+\s*\(|\bfsockopen\s*\(|\bpfsockopen\s*\(|\bstream_socket_client\s*\(|\bstream_context_create\s*\(|\bsocket_\w+\s*\(|(?:file_get_contents|fopen|file|readfile|copy)\s*\(\s*[\'"](?:https?|ftp):|\bAnthropic\\\\/i';

/**
 * The one environment read Linda makes, and why: AnthropicModel refuses to
 * call out while ANTHROPIC_CUSTOM_HEADERS is set, because the SDK would add
 * those headers to every request. It only checks that the name is set and
 * never reads a value, so these exact checks are taken out before the scan.
 */
$allowedEnvChecks = [
    'app/Services/Linda/Model/AnthropicModel.php' => [
        'getenv(self::CUSTOM_HEADERS_ENV) !== false',
        'array_key_exists(self::CUSTOM_HEADERS_ENV, $_ENV)',
    ],
];

/** The files that may call out: the Anthropic model and its HTTP guard. */
$mayCallOut = [
    'app/Services/Linda/Model/AnthropicModel.php',
    'app/Services/Linda/Model/LindaHttpGuard.php',
];

/** @return list<string> "<file> uses <what>" for every forbidden use */
$violations = function (array $paths) use ($codeOf, $stringsOf, $relative, $forbidden, $rawQualified, $outbound, $mayCallOut, $allowedEnvChecks): array {
    $found = [];

    foreach ($paths as $path) {
        $file = $relative($path);
        $code = str_replace($allowedEnvChecks[$file] ?? [], '', $codeOf($path));

        foreach ($forbidden as $what => $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $found[] = $file.' uses '.$what;
            }
        }

        if (preg_match($rawQualified, $stringsOf($path)) === 1) {
            $found[] = $file.' uses a schema-qualified table in SQL';
        }

        // The backtick operator runs a shell command; as a token it is a bare "`".
        if (in_array('`', token_get_all((string) file_get_contents($path)), true)) {
            $found[] = $file.' uses a process';
        }

        if (! in_array($file, $mayCallOut, true) && preg_match($outbound, $code) === 1) {
            $found[] = $file.' calls out';
        }
    }

    return $found;
};

it('finds every Linda file, config/linda.php aside', function () use ($lindaSources, $relative) {
    $files = array_map($relative, $lindaSources());

    // A floor, so the discovery can't silently shrink.
    expect($files)->toContain(
        'app/Services/Linda/LindaAssistant.php',
        'app/Services/Linda/LindaFinder.php',
        'app/Services/Linda/LindaWording.php',
        'app/Services/Linda/Answers/MemberLookupAnswer.php',
        'app/Services/Linda/Answers/ReceiptLookupAnswer.php',
        'app/Services/Linda/Model/AnthropicModel.php',
        'app/Services/Linda/Model/LindaHttpGuard.php',
        'app/Services/Linda/Model/LindaModelFactory.php',
        'app/Http/Controllers/Api/Linda/LindaController.php',
        'app/Http/Controllers/Api/Linda/LindaSettingsController.php',
        'app/Http/Requests/Linda/LindaChatRequest.php',
        'app/Http/Requests/Linda/UpdateLindaSettingsRequest.php',
        'app/Http/Middleware/EnsureLindaEnabled.php',
        'app/Models/LindaQuestion.php',
        'app/Models/LindaSetting.php',
        'app/Console/Commands/EvaluateLinda.php',
        'app/Console/Commands/PruneLindaQuestions.php',
        'routes/api/linda.php',
        'routes/console/linda.php',
        'database/migrations/2026_11_16_100100_create_linda_questions_table.php',
        'database/migrations/2026_11_16_100200_create_linda_settings_table.php',
    );

    expect(count($files))->toBeGreaterThanOrEqual(60)
        ->and($files)->not->toContain('config/linda.php');
});

it('reads only the deployment\'s own database, cache and settings, and calls out only from the model driver', function () use ($lindaSources, $violations) {
    expect($violations($lindaSources()))->toBe([]);
});

it('lets only the Anthropic model and its HTTP guard call out', function () use ($mayCallOut, $root) {
    foreach ($mayCallOut as $file) {
        expect(is_file($root.'/'.$file))->toBeTrue($file.' is missing');
    }

    expect($mayCallOut)->toHaveCount(2);
});
