<?php

/**
 * LO-B3: messages to online applicants are logged, never sent (testing.md:
 * never send SMS or email to real people; the reminders decision: real
 * sending comes after the owner signs). So the staff processing code holds no
 * way to send: no mail, no SMS or reminder driver, no HTTP client and no
 * notification routed to an address. And the message log is append-only: no
 * statement under app/ bulk-writes `origination_applicant_messages`.
 */
const LO3_STAFF_DIRS = ['Services/LoanOrigination/Staff', 'Http/Controllers/Api/LoanOrigination/Staff'];

/** @return iterable<string, string> path => source */
function lo3StaffSources(): iterable
{
    $root = dirname(__DIR__, 2).'/app/';

    foreach (LO3_STAFF_DIRS as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.$dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                yield substr($file->getPathname(), strlen($root)) => (string) file_get_contents($file->getPathname());
            }
        }
    }
}

it('finds the staff processing code', function () {
    expect(iterator_to_array(lo3StaffSources()))->not->toBe([]);
});

it('holds no way to reach an applicant', function () {
    $sending = '/\b(?:Mail|Http)::|Notification::route|\b(?:DriverResolver|SmsDriver|EmailDriver|OtpSender)\b|->notify\(|Facades\\\\(?:Mail|Http)\b|\bcurl_|fsockopen|stream_socket_client/';
    $offenders = [];

    foreach (lo3StaffSources() as $path => $source) {
        if (preg_match($sending, $source, $match)) {
            $offenders[] = "{$path}: {$match[0]}";
        }
    }

    expect($offenders)->toBe([]);
});

it('never bulk-writes the applicant message log', function () {
    $root = dirname(__DIR__, 2).'/app';
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (explode(';', (string) file_get_contents($file->getPathname())) as $statement) {
            $names = preg_match('/\bOriginationApplicantMessage::(?!class\b)|DB::table\(\s*[\'"]origination_applicant_messages[\'"]\s*\)|->\s*applicantMessages\s*\(\s*\)/', $statement);
            $writes = preg_match('/->\s*(?:update|updateOrInsert|upsert|increment|decrement|delete|forceDelete|truncate|toBase)\s*\(/', $statement);
            $raw = preg_match('/\bDB::(?:update|delete|statement|affectingStatement|unprepared)\s*\(/', $statement) && str_contains($statement, 'origination_applicant_messages');

            if (($names && $writes) || $raw) {
                $offenders[] = substr($file->getPathname(), strlen($root) + 1).': '.trim((string) preg_replace('/\s+/', ' ', $statement));
            }
        }
    }

    expect($offenders)->toBe([]);
});
