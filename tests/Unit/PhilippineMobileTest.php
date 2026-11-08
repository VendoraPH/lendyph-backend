<?php

use App\Support\PhilippineMobile;

/*
 * One stored form for a Philippine mobile number, 639XXXXXXXXX, whatever way a
 * person typed it. Anything that isn't a mobile number (a landline, a short or
 * long number, letters) is null, so a caller never sends to a guess.
 */

it('normalises every accepted mobile format to 639XXXXXXXXX', function (string $input) {
    expect(PhilippineMobile::normalise($input))->toBe('639171234567');
})->with([
    'local 09' => '09171234567',
    'without the leading 0' => '9171234567',
    'country code' => '639171234567',
    'plus country code' => '+639171234567',
    'plus country code with spaces' => '+63 917 123 4567',
    'local with dashes' => '0917-123-4567',
    'local with brackets' => '(0917) 123-4567',
    'plus country code with brackets and dashes' => '+63 (917) 123-4567',
    'surrounding spaces' => '  0917 123 4567  ',
]);

it('refuses anything that is not a Philippine mobile number', function (?string $input) {
    expect(PhilippineMobile::normalise($input))->toBeNull();
})->with([
    'null' => null,
    'empty' => '',
    'only separators' => ' - ( ) ',
    'Metro Manila landline' => '(02) 8123-4567',
    'Metro Manila landline, plain' => '0281234567',
    'provincial landline' => '(032) 123-4567',
    'landline with country code' => '+63 2 8123 4567',
    'landline with country code, plain' => '6328123456',
    'one digit short' => '0917123456',
    'one digit long' => '091712345678',
    'country code, one digit short' => '63917123456',
    'plus without the country code' => '+09171234567',
    'plus in the middle' => '0917+1234567',
    'international 00 prefix' => '00639171234567',
    'letters' => '0917abc4567',
    'dots are not separators' => '0917.123.4567',
    'another country code' => '+6591234567',
]);

it('masks a number, keeping only the prefix and the last two digits', function (string $input, string $masked) {
    expect(PhilippineMobile::mask($input))->toBe($masked);
})->with([
    'normalised' => ['639171234567', '63917*****67'],
    'typed locally' => ['0917 123 4567', '63917*****67'],
    'typed with plus' => ['+63 917 123 4567', '63917*****67'],
]);

it('never shows a number it cannot read in full', function (string $input, string $masked) {
    expect(PhilippineMobile::mask($input))->toBe($masked);
})->with([
    'landline' => ['(02) 8123-4567', '028*****67'],
    'short' => ['12345', '*****'],
    'empty' => ['', ''],
]);
