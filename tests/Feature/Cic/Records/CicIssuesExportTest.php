<?php

use App\Models\AuditLog;
use App\Models\CicCapture;
use App\Models\LoanProduct;
use App\Services\Cic\Capture\CicCaptureService;
use App\Services\Cic\CicAccess;
use App\Services\Cic\CicIssueWords;
use Tests\Feature\Cic\Records\CicRecordsFixtures;
use Tests\TestCase;

uses(TestCase::class, CicRecordsFixtures::class);

/*
 * GET /cic/issues/export: the issues list's filters, as a CSV built on the
 * server, with codes in words, member names only with borrowers:view, and one
 * audit row per export (filters and row count, never values).
 */

beforeEach(function () {
    $this->cicOn();
    $this->viewer = $this->cicUser([CicAccess::VIEW, 'branches:view_all']);
    $this->reader = $this->cicUser([CicAccess::VIEW, 'branches:view_all', 'borrowers:view']);
});

/** @return list<list<string>> */
function cicCsv(string $content): array
{
    return array_map(str_getcsv(...), array_values(array_filter(explode("\n", str_replace("\r\n", "\n", $content)), fn (string $line) => $line !== '')));
}

it('exports every matching issue with codes in words and member names for borrowers:view holders, audited', function () {
    $member = $this->cicMember(['first_name' => 'Rosalinda', 'birthdate' => null]);
    $this->cicLoan($member, LoanProduct::factory()->create(['name' => 'Emergency Loan']));

    $response = $this->actingAs($this->reader)->get('/api/cic/issues/export?reference_date=2026-11-30&severity=error', ['Accept' => 'application/json'])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('cic-issues-2026-11-30.csv');
    $content = $response->streamedContent();
    $rows = cicCsv($content);

    expect($rows[0])->toBe(['Record type', 'Record key', 'Field ID', 'Field name', 'Classification', 'Severity', 'Issue', 'Message', 'Member'])
        ->and($content)->not->toContain('MISSING_MANDATORY')->not->toContain('UNMAPPED_PRODUCT')->not->toContain(',error,');

    $birthdate = collect(array_slice($rows, 1))->first(fn (array $row) => $row[2] === 'ID14');
    $unmapped = collect(array_slice($rows, 1))->first(fn (array $row) => $row[6] === 'Loan product not mapped');
    expect($birthdate)->toMatchArray([0 => 'Subject (ID)', 1 => $member->borrower_code, 4 => 'Mandatory', 5 => 'Error', 6 => 'Missing required field'])
        ->and($birthdate[8])->toBe("Rosalinda Santos Cruz ({$member->borrower_code})")
        ->and($unmapped[0])->toBe('Instalment contract (CI)')
        ->and($unmapped[8])->toBe("Rosalinda Santos Cruz ({$member->borrower_code})")
        ->and(collect(array_slice($rows, 1))->pluck(5)->unique()->values()->all())->toBe(['Error']);

    $audit = AuditLog::query()->where('action', 'cic_issues_exported')->sole();
    expect($audit->user_id)->toBe($this->reader->id)
        ->and($audit->auditable_type)->toBeNull()
        ->and($audit->new_values)->toMatchArray(['source' => 'live', 'reference_date' => '2026-11-30', 'severity' => 'error', 'record_type' => null, 'rows' => count($rows) - 1, 'with_member_names' => true])
        ->and($audit->description)->toBe(sprintf('CIC issues exported from the live build as of 2026-11-30: %d rows (errors only).', count($rows) - 1))
        ->and(json_encode($audit->getAttributes()))->not->toContain('Rosalinda');
});

it('leaves out member names without borrowers:view, and reads a capture when one is named', function () {
    $member = $this->cicMember(['first_name' => 'Rosalinda', 'birthdate' => null]);
    $this->cicLoan($member);
    config(['cic.test_captures' => true]);
    $capture = app(CicCaptureService::class)->capture(now(), CicCapture::KIND_TEST, null);

    $response = $this->actingAs($this->viewer)->get("/api/cic/issues/export?capture_id={$capture->id}&record_type=ID", ['Accept' => 'application/json'])
        ->assertOk()->assertDownload("cic-issues-capture-{$capture->id}-2026-11-30.csv");
    $content = $response->streamedContent();
    $rows = cicCsv($content);

    expect($rows[0])->not->toContain('Member')
        ->and($content)->not->toContain('Rosalinda')
        ->and(collect(array_slice($rows, 1))->pluck(0)->unique()->values()->all())->toBe(['Subject (ID)']);

    $audit = AuditLog::query()->where('action', 'cic_issues_exported')->sole();
    expect($audit->auditable_type)->toBe(CicCapture::class)
        ->and($audit->auditable_id)->toBe($capture->id)
        ->and($audit->new_values)->toMatchArray(['source' => 'capture', 'capture_id' => $capture->id, 'record_type' => 'ID', 'with_member_names' => false])
        ->and($audit->description)->toContain("capture #{$capture->id} (2026-11-30)")->toContain('Subject (ID) records only');
});

it('needs cic:view with head-office access, checks its filters in words, and is throttled like other exports', function () {
    $this->actingAs($this->cicUser(['branches:view_all']))->getJson('/api/cic/issues/export')->assertForbidden();
    $this->actingAs($this->reader)->getJson('/api/cic/issues/export?severity=fatal')
        ->assertUnprocessable()->assertJsonPath('errors.severity.0', 'The severity must be error or warning.');
    $this->actingAs($this->reader)->getJson('/api/cic/issues/export?capture_id=999999')->assertNotFound();

    // The two refusals above count too: 5 exports a minute.
    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($this->reader)->get('/api/cic/issues/export', ['Accept' => 'application/json'])->assertOk();
    }
    $this->actingAs($this->reader)->getJson('/api/cic/issues/export')->assertTooManyRequests();

    config(['cic.enabled' => false]);
    $this->actingAs($this->viewer)->getJson('/api/cic/issues/export')->assertNotFound();
});

it('has words for every issue code the checks raise', function () {
    $sources = [base_path('app/Services/Cic/CicValidator.php'), ...glob(base_path('app/Services/Cic/Records/*.php'))];
    $codes = [];

    foreach ($sources as $file) {
        preg_match_all("/'([A-Z]+(?:_[A-Z]+)+)'/", (string) file_get_contents($file), $matches);
        $codes = [...$codes, ...$matches[1]];
    }

    $codes = array_values(array_unique($codes));

    expect($codes)->not->toBeEmpty()
        ->and(array_values(array_diff($codes, array_keys(CicIssueWords::CODES))))->toBe([])
        ->and(CicIssueWords::code('SOMETHING_NEW'))->toBe('CIC format check');
});
