<?php

/*
 * LO-B4: the hand-off creates and submits its loan in one loan transaction,
 * so it can't call submitForReview() (which must be the outermost
 * transaction). It runs createLoanRecord(), the record POST /loans creates,
 * and then submitForReview()'s own statements, in the same order. This test
 * keeps the two from drifting apart: a change to submitForReview()'s
 * transaction body fails it until the hand-off takes the same change. The
 * hand-off entry sits at the end of LoanService, below every line
 * CollateralIntegrityGuardsTest pins.
 */

/** A method's body, from its signature to its closing brace. */
function lo4MethodBody(string $source, string $method): string
{
    $start = strpos($source, "public function {$method}(");
    expect($start)->not->toBeFalse("LoanService::{$method}() not found");

    $open = strpos($source, '{', strpos($source, ')', $start));
    $depth = 0;

    for ($i = $open, $n = strlen($source); $i < $n; $i++) {
        $depth += $source[$i] === '{' ? 1 : ($source[$i] === '}' ? -1 : 0);

        if ($depth === 0) {
            return substr($source, $open, $i - $open + 1);
        }
    }

    throw new RuntimeException("Unbalanced braces in {$method}()");
}

/**
 * The statements inside the LoanWriteTransaction::run() closure, comments
 * and blank lines left out.
 *
 * @return list<string>
 */
function lo4TransactionStatements(string $body, string $after = ''): array
{
    $from = strpos($body, 'LoanWriteTransaction::run(function');
    $inside = substr($body, $from === false ? 0 : $from);

    if ($after !== '') {
        $inside = substr($inside, strpos($inside, $after) + strlen($after));
    }

    $lines = array_map('trim', explode("\n", $inside));

    return array_values(array_filter($lines, fn (string $line) => $line !== '' && ! str_starts_with($line, '//') && ! str_starts_with($line, '*') && ! str_starts_with($line, '/*')));
}

it('submits the handed-off loan with exactly submitForReview()\'s statements, after createLoanRecord()', function () {
    $source = (string) file_get_contents(__DIR__.'/../../app/Services/LoanService.php');

    $submit = lo4TransactionStatements(lo4MethodBody($source, 'submitForReview'));
    $submitCore = array_slice($submit, 1, array_search('return $loan;', $submit, true) - 1);
    $handOff = lo4TransactionStatements(lo4MethodBody($source, 'createAndSubmitForHandOff'));

    $created = array_search('$loan = $this->createLoanRecord($validated, $user);', $handOff, true);
    expect($created)->not->toBeFalse();

    $afterCreate = array_slice($handOff, $created + 1);
    $coreStart = array_search($submitCore[0], $afterCreate, true);

    expect($submitCore)->not->toBeEmpty()
        ->and($coreStart)->not->toBeFalse()
        ->and(array_slice($afterCreate, $coreStart, count($submitCore)))->toBe($submitCore);
});

it('sits at the very end of LoanService, below every pinned status write', function () {
    $source = (string) file_get_contents(__DIR__.'/../../app/Services/LoanService.php');

    $handOffAt = strpos($source, 'public function createAndSubmitForHandOff(');
    $lastOtherMethod = strrpos(substr($source, 0, $handOffAt), 'function guardStatus(');

    expect($handOffAt)->toBeGreaterThan($lastOtherMethod)
        ->and(preg_match_all('/\n    (public|private|protected) function /', substr($source, $handOffAt + 10)))->toBe(0);
});
