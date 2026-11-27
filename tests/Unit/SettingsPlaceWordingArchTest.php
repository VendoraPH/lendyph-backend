<?php

/**
 * Every module's settings live under the main Settings menu (frontend #650):
 * Settings → Accounting, Savings, Savings Products, CDA, Credit Scoring and
 * Scorecard Configuration, each keeping its own sections ("Settings →
 * Accounting → Default Accounts"). So no message under app/ sends staff to the
 * old places. Only string literals are read, so comments don't count. Linda's
 * menu map (Services/Linda/LindaMenu.php) is held by Linda's own tests.
 */
$oldPlaces = [
    'Accounting Settings', 'Accounting → Settings', '→ Settings → Default Accounts', 'Savings Settings', 'Savings → Settings',
    'Savings → Products', 'CDA → Settings', 'Credit Scoring → Settings', 'in CDA settings',
];

/** @return list<string> the old places named in the code's string literals */
$oldPlacesIn = function (string $code) use ($oldPlaces): array {
    $found = [];

    foreach (token_get_all($code) as $token) {
        if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }

        foreach ($oldPlaces as $place) {
            if (str_contains($token[1], $place)) {
                $found[] = $place;
            }
        }
    }

    return $found;
};

it('finds an old settings place in a string, never in a comment', function () use ($oldPlacesIn) {
    $code = "<?php\n// Accounting Settings\n\$a = 'Open Accounting '\n    .'→ Settings → Default Accounts';\n\$b = \"Add one in Savings → Products for {\$name}.\";\n";

    expect($oldPlacesIn($code))->toBe(['→ Settings → Default Accounts', 'Savings → Products']);
});

it('sends staff to the settings under the main Settings menu', function () use ($oldPlacesIn) {
    $root = dirname(__DIR__, 2);
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = $file->getPathname();

        if (! str_ends_with($path, '.php') || str_ends_with($path, '/Services/Linda/LindaMenu.php')) {
            continue;
        }

        foreach ($oldPlacesIn((string) file_get_contents($path)) as $place) {
            $found[] = substr($path, strlen($root) + 1).': '.$place;
        }
    }

    sort($found);

    expect($found)->toBe([]);
});
