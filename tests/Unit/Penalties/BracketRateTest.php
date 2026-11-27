<?php

use App\Services\Penalties\BracketRate;

/*
 * The bracket lookup the engine uses (RepaymentService::penaltyCharges()):
 * days are counted past the due date, the day after it being day 1, and a day
 * no bracket covers is no charge.
 */

function kevinsBrackets(): array
{
    return [
        ['days_from' => 1, 'days_to' => 5, 'rate' => 20],
        ['days_from' => 6, 'days_to' => 10, 'rate' => 30],
        ['days_from' => 11, 'days_to' => null, 'rate' => 50],
    ];
}

it('takes the rate of the bracket covering the day', function (int $day, float $rate) {
    expect(BracketRate::rateFor(kevinsBrackets(), $day))->toBe($rate);
})->with([
    'day 1' => [1, 20.0],
    'day 5' => [5, 20.0],
    'day 6' => [6, 30.0],
    'day 10' => [10, 30.0],
    'day 11' => [11, 50.0],
    'day 40' => [40, 50.0],
]);

it('has no rate for a day no bracket covers', function () {
    $closed = [['days_from' => 1, 'days_to' => 5, 'rate' => 20]];

    expect(BracketRate::rateFor($closed, 6))->toBeNull()
        ->and(BracketRate::rateFor($closed, 0))->toBeNull();
});

it('reads brackets in words, rates never rounded', function () {
    expect(BracketRate::inWords(kevinsBrackets()))->toBe('1–5 days: 20%; 6–10 days: 30%; 11 days or more: 50%')
        ->and(BracketRate::inWords([['days_from' => 1, 'days_to' => null, 'rate' => '2.5000']]))->toBe('1 day or more: 2.5%')
        ->and(BracketRate::inWords([
            ['days_from' => 2, 'days_to' => 2, 'rate' => 0.1234],
            ['days_from' => 1, 'days_to' => 1, 'rate' => 0],
        ]))->toBe('1 day: 0%; day 2: 0.1234%');
});

it('writes a rate with the decimals it has', function (int|float|string $rate, string $words) {
    expect(BracketRate::percent($rate))->toBe($words);
})->with([
    [20, '20%'],
    [2.5, '2.5%'],
    ['2.5000', '2.5%'],
    ['12.3456', '12.3456%'],
    [100, '100%'],
    [0.0, '0%'],
]);

it('charges anything only with a rate above 0', function () {
    expect(BracketRate::chargesAnything(kevinsBrackets()))->toBeTrue()
        ->and(BracketRate::chargesAnything([['days_from' => 1, 'days_to' => null, 'rate' => 0]]))->toBeFalse();
});
