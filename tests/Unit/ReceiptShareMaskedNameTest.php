<?php

use App\Services\ReceiptShareLinks\MaskedName;

/*
 * A shared receipt names the member by initials and surname (finish run
 * round 2, decision 6): one initial per word of the first name, one for the
 * middle name, then the surname and suffix as stored.
 */

it('reads initials and the surname', function (?string $first, ?string $middle, ?string $last, ?string $suffix, string $shown) {
    expect(MaskedName::of($first, $middle, $last, $suffix))->toBe($shown);
})->with([
    'first, middle and last' => ['Juan', 'Pablo', 'Dela Cruz', null, 'J. P. Dela Cruz'],
    'two first names and a two-word middle name' => ['Maria Clara', 'dela Cruz', 'Santos', null, 'M. C. D. Santos'],
    'no middle name' => ['Juan', null, 'Santos', null, 'J. Santos'],
    'a blank middle name' => ['Juan', '  ', 'Santos', null, 'J. Santos'],
    'a suffix' => ['Jose', 'Rizal', 'Mercado', 'Jr.', 'J. R. Mercado Jr.'],
    'an abbreviated first name' => ['Ma. Luisa', null, 'Reyes', null, 'M. L. Reyes'],
    'lowercase and accented letters' => ['ñora', 'éva', 'Peña', null, 'Ñ. É. Peña'],
    'extra spaces' => ['  Ana   Marie ', null, '  Lim  ', null, 'A. M. Lim'],
    'a word with no letter' => ['Juan -', null, 'Cruz', null, 'J. Cruz'],
]);

it('never shows a first or middle name in full', function () {
    $shown = MaskedName::of('Leonora', 'Bautista', 'Villanueva', null);

    expect($shown)->toBe('L. B. Villanueva')
        ->and($shown)->not->toContain('Leonora')
        ->and($shown)->not->toContain('Bautista');
});

it('reads Member when there is nothing to show', function () {
    expect(MaskedName::of(null, null, null, null))->toBe('Member')
        ->and(MaskedName::of('', ' ', '', ''))->toBe('Member');
});
