<?php

/**
 * Linda belongs to the one deployment it runs in (Arvin, 2026-11-16): each
 * domain's Linda answers only from that deployment's own database and keeps
 * nothing anywhere another deployment could read.
 *
 * The deployments are already separate (their own API, database, cache and
 * files, chosen by each box's .env). This keeps Linda's code from stepping
 * around that: it never names a database connection or a cache store, never
 * touches Redis or a file disk, never reads env() directly, and only the model
 * driver (app/Services/Linda/Model) may call out, sending the question text
 * and nothing else (LindaPrompt and its tests).
 *
 * The scan reads tokens, not text, so docblocks that name a call don't count.
 */
$lindaSources = function (): array {
    $root = dirname(__DIR__, 2);

    return [
        ...(glob($root.'/app/Services/Linda/*.php') ?: []),
        ...(glob($root.'/app/Services/Linda/*/*.php') ?: []),
        ...(glob($root.'/app/Http/Controllers/Api/Linda/*.php') ?: []),
        $root.'/app/Models/LindaQuestion.php',
        $root.'/app/Console/Commands/EvaluateLinda.php',
        $root.'/app/Console/Commands/PruneLindaQuestions.php',
    ];
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

$relative = fn (string $path): string => substr($path, strlen(dirname(__DIR__, 2)) + 1);

it('scans every Linda file', function () use ($lindaSources, $relative) {
    $files = array_map($relative, $lindaSources());

    expect($files)
        ->toContain('app/Services/Linda/LindaAssistant.php')
        ->toContain('app/Services/Linda/LindaFinder.php')
        ->toContain('app/Services/Linda/Answers/MemberLookupAnswer.php')
        ->toContain('app/Services/Linda/Model/AnthropicModel.php')
        ->toContain('app/Http/Controllers/Api/Linda/LindaController.php');

    foreach ($lindaSources() as $path) {
        expect(is_file($path))->toBeTrue($relative($path).' is missing');
    }
});

it('reads only the deployment\'s own database, cache and settings', function () use ($lindaSources, $codeOf, $relative) {
    $forbidden = [
        'a named database connection' => '/DB::connection\s*\(|->connection\s*\(|::on\s*\(|->setConnection\s*\(|\$connection\s*=/',
        'a named cache store' => '/Cache::store\s*\(|cache\s*\(\s*\)\s*->\s*store\s*\(/i',
        'Redis' => '/\bRedis::|Illuminate\\\\Support\\\\Facades\\\\Redis\b/',
        'a file disk' => '/\bStorage::|->disk\s*\(/',
        'env()' => '/(?<![\w>:$])env\s*\(/',
    ];

    $found = [];
    foreach ($lindaSources() as $path) {
        $code = $codeOf($path);
        foreach ($forbidden as $what => $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $found[] = $relative($path).' uses '.$what;
            }
        }
    }

    expect($found)->toBe([]);
});

it('calls out only from the model driver', function () use ($lindaSources, $codeOf, $relative) {
    $outbound = '/\bHttp::|GuzzleHttp\\\\|\bcurl_\w+\s*\(|\bfsockopen\s*\(|\bstream_socket_client\s*\(|file_get_contents\s*\(\s*[\'"]https?:|\bAnthropic\\\\/';

    $found = [];
    foreach ($lindaSources() as $path) {
        $file = $relative($path);
        if (str_starts_with($file, 'app/Services/Linda/Model/')) {
            continue;
        }

        if (preg_match($outbound, $codeOf($path)) === 1) {
            $found[] = $file;
        }
    }

    expect($found)->toBe([]);
});
