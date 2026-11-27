<?php

use App\Models\ShareCapitalLedger;
use Tests\Feature\CreditScoring\Engine\CreditFixtures;
use Tests\TestCase;

uses(TestCase::class, CreditFixtures::class);

beforeEach(fn () => $this->pinToday());

/**
 * The score without the parts that carry the calculation time, so two
 * calculations of the same data compare equal.
 *
 * @param  array<string, mixed>  $result
 * @return array<string, mixed>
 */
function comparableScore(array $result): array
{
    return [
        'score' => $result['score'],
        'risk_level' => $result['risk_level'],
        'confidence' => $result['confidence'],
        'categories' => $result['categories'],
        'factors' => $result['factors'],
        'capacity' => $result['capacity'],
        'data_used' => $result['data_used'],
        'flags' => $result['flags'],
    ];
}

it('leaves the score unchanged when birthdate, civil status, gender, home or spouse details change', function () {
    $member = $this->member(['monthly_income' => 30000]);
    $loan = $this->releasedLoan($member);
    $this->paidMonthly($loan, '2026-06-01', 4);
    $this->period($loan, 5, '2026-10-01');

    $before = comparableScore($this->scoreOf($member));

    $member->update([
        'birthdate' => '1950-01-01',
        'civil_status' => $member->civil_status === 'single' ? 'married' : 'single',
        'gender' => $member->gender === 'male' ? 'female' : 'male',
        'address' => 'Somewhere else entirely',
        'street_address' => '1 New Street',
        'barangay' => 'Another Barangay',
        'city' => 'Another City',
        'province' => 'Another Province',
        'spouse_first_name' => 'Pat',
        'spouse_middle_name' => 'Q',
        'spouse_last_name' => 'Reyes',
        'spouse_contact_number' => '09998887777',
        'spouse_occupation' => 'Teacher',
    ]);

    expect(comparableScore($this->scoreOf($member->fresh())))->toBe($before);
});

it('leaves the score unchanged when the member\'s share capital changes', function () {
    $member = $this->member(['monthly_income' => 30000]);
    $loan = $this->releasedLoan($member);
    $this->paidMonthly($loan, '2026-06-01', 4);

    $before = comparableScore($this->scoreOf($member));

    ShareCapitalLedger::factory()->count(3)->create(['borrower_id' => $member->id]);

    expect(comparableScore($this->scoreOf($member->fresh())))->toBe($before);
});

it('never names a sensitive column or share capital in the engine\'s source', function () {
    $root = dirname(__DIR__, 4).'/app/Services/CreditScoring';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $hits = [];

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        if (preg_match_all('/birthdate|civil_status|gender|address|spouse|share_capital|sharecapital|scb_amount|pledge/i', $source, $matches)) {
            $hits[$file->getFilename()] = array_values(array_unique($matches[0]));
        }
    }

    expect($hits)->toBe([]);
});
