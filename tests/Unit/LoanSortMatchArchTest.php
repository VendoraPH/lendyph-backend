<?php

use App\Http\Controllers\Api\LoanController;

/**
 * LoanController::index() validates `?sort=` with Rule::in(self::SORT_KEYS) and
 * then hands the approved value to a `match ($sort)` in applySort() whose arms
 * mirror that same list. The two are the same list written twice, and nothing in
 * PHP ties them together: add a key to SORT_KEYS without adding an arm and the
 * validator starts approving a value the match cannot handle, so the request
 * dies on an \UnhandledMatchError — a 500 on input the API just told the caller
 * was valid.
 *
 * A `default` arm would remove the 500 and is deliberately NOT the fix: it turns
 * an unhandled key into a silent sort by whatever the fallback happens to be,
 * so the list quietly ignores the sort the user asked for and nobody finds out.
 * Security review asked for the divergence to stay loud, which means the guard
 * has to live outside the code — here.
 *
 * No database, no application boot: this reads the class's own source.
 */

/**
 * The body of a method, as written.
 */
function methodSourceOf(string $class, string $method): string
{
    $reflection = new ReflectionMethod($class, $method);

    return implode('', array_slice(
        file($reflection->getFileName()),
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));
}

/**
 * The arm keys of the single `match` expression inside a method.
 *
 * Tokenised rather than regexed so that a `,` or `=>` inside an arm body cannot
 * be mistaken for an arm boundary.
 *
 * @return array{keys: array<int, string>, has_default: bool, unreadable: array<int, string>}
 */
function matchArmsOf(string $class, string $method): array
{
    $tokens = PhpToken::tokenize('<?php '.methodSourceOf($class, $method));

    $matchPositions = [];

    foreach ($tokens as $position => $token) {
        if ($token->is(T_MATCH)) {
            $matchPositions[] = $position;
        }
    }

    expect($matchPositions)->toHaveCount(
        1,
        sprintf(
            '%s::%s() must contain exactly one match expression for this guard to read; found %d. '
            .'If the sort dispatch moved or was split, point this test at its new home rather than deleting it.',
            $class,
            $method,
            count($matchPositions),
        ),
    );

    // Walk past the match subject — `match ($sort)` — to the `{` opening the arms.
    $position = $matchPositions[0];
    $parens = 0;

    for ($i = $position; $i < count($tokens); $i++) {
        $text = $tokens[$i]->text;

        if ($text === '(') {
            $parens++;
        } elseif ($text === ')') {
            $parens--;
        } elseif ($text === '{' && $parens === 0) {
            $position = $i + 1;
            break;
        }
    }

    $depth = 1;
    $expectingKey = true;
    $keys = [];
    $hasDefault = false;
    $unreadable = [];

    for ($i = $position; $i < count($tokens); $i++) {
        $token = $tokens[$i];
        $text = $token->text;

        if (in_array($text, ['{', '(', '['], true)) {
            $depth++;

            continue;
        }

        if (in_array($text, ['}', ')', ']'], true)) {
            $depth--;

            if ($depth === 0) {
                break;
            }

            continue;
        }

        if ($depth !== 1 || $token->isIgnorable()) {
            continue;
        }

        if (! $expectingKey) {
            // A `,` at arm depth ends the arm body and starts the next key.
            if ($text === ',') {
                $expectingKey = true;
            }

            continue;
        }

        if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
            $keys[] = substr($text, 1, -1);
        } elseif ($token->is(T_DEFAULT)) {
            $hasDefault = true;
        } elseif ($token->is(T_DOUBLE_ARROW)) {
            $expectingKey = false;
        } elseif ($text !== ',') {
            // A `,` while still expecting a key is the separator between
            // several keys sharing one arm body (`'a', 'b' => ...`), so it is
            // skipped rather than reported. Anything else is an arm key this
            // parser cannot read — a constant, say — and is surfaced instead of
            // being dropped, because dropping it would silently weaken the
            // guard rather than fail it.
            $unreadable[] = $text;
        }
    }

    return ['keys' => $keys, 'has_default' => $hasDefault, 'unreadable' => $unreadable];
}

it('handles every sort key the validator accepts', function () {
    $sortKeys = (new ReflectionClass(LoanController::class))->getConstant('SORT_KEYS');

    expect($sortKeys)->toBeArray()->not->toBeEmpty();

    $arms = matchArmsOf(LoanController::class, 'applySort');

    expect($arms['unreadable'])->toBe([], sprintf(
        'The match in LoanController::applySort() now has an arm key this guard cannot read (%s). '
        .'It only understands literal string keys. Either keep the arm keys literal, or teach '
        .'matchArmsOf() the new form — do not delete the assertion.',
        implode(', ', $arms['unreadable']),
    ));

    $missingArms = array_values(array_diff($sortKeys, $arms['keys']));
    $orphanArms = array_values(array_diff($arms['keys'], $sortKeys));

    $instructions = [];

    if ($missingArms !== []) {
        $instructions[] = sprintf(
            'ADD an arm to the match ($sort) in LoanController::applySort() for: %s. '
            .'Rule::in(self::SORT_KEYS) already lets callers send these, so today they reach the match, '
            .'hit no arm and raise \UnhandledMatchError — a 500 on a value the validator approved. '
            .'Do NOT add a default arm to silence this: a default makes an unhandled key sort by the '
            .'wrong column quietly instead of failing loudly.',
            implode(', ', $missingArms),
        );
    }

    if ($orphanArms !== []) {
        $instructions[] = sprintf(
            'REMOVE these arms from the match in LoanController::applySort(), or add them back to '
            .'self::SORT_KEYS: %s. They are unreachable — the validator rejects anything not in '
            .'SORT_KEYS with a 422, so no request can ever select them.',
            implode(', ', $orphanArms),
        );
    }

    // fail() rather than an expectation on the instruction list: the point of
    // this test is the instruction, and a diff of two arrays of prose would
    // print it twice and bury it.
    if ($instructions !== []) {
        $this->fail("LoanController::SORT_KEYS and its match arms have diverged.\n\n".implode("\n\n", $instructions));
    }

    expect($arms['keys'])->toEqualCanonicalizing($sortKeys);
});

it('has no default arm hiding an unhandled sort key', function () {
    $arms = matchArmsOf(LoanController::class, 'applySort');

    expect($arms['has_default'])->toBeFalse(
        'A default arm was added to the match in LoanController::applySort(). Remove it. '
        .'Without one, a sort key that has no arm raises \UnhandledMatchError and the divergence is '
        .'found immediately; with one, the list silently sorts by something the caller did not ask '
        .'for and the bug ships. The sibling test above is what keeps the arms and SORT_KEYS in step.'
    );
});

/**
 * The third leg. Matching arms to SORT_KEYS only protects the endpoint while
 * SORT_KEYS is also what the validator enforces: loosen `?sort=` to a free-form
 * string and every unlisted value reaches the match and 500s, with both tests
 * above still green.
 */
it('validates the sort parameter against the same constant the match is built from', function () {
    // Reduced to a boolean before asserting: expecting on the source itself
    // prints the whole of index() on failure and buries the instruction.
    $pinnedToSortKeys = (bool) preg_match(
        "/'sort'\s*=>\s*\[[^\]]*Rule::in\(self::SORT_KEYS\)/",
        methodSourceOf(LoanController::class, 'index'),
    );

    expect($pinnedToSortKeys)->toBeTrue(<<<'MESSAGE'
        LoanController::index() no longer validates `?sort=` with Rule::in(self::SORT_KEYS).

        SORT_KEYS has to be the ONE list: the validator's whitelist and the match arms in
        applySort(). Anything the validator lets through that the match has no arm for is an
        \UnhandledMatchError, and the value goes straight into an ORDER BY clause, so the
        whitelist is also what keeps caller input out of SQL. Restore the rule.
        MESSAGE);
});
