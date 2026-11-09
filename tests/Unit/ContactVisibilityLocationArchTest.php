<?php

/**
 * ContactVisibility is the round fz personal-data rule (`borrowers:view` and
 * the member's own branch). Reports, printables, loans and the Audit Trail use
 * it as well as the reminder screens, so it lives in App\Services, beside
 * PersonalDataKeys, not under the reminders API (Lead's call 9).
 *
 * No database, no application boot: this reads the app's own source.
 */
it('lives in App\Services, and nothing still names the reminders API copy', function () {
    $old = 'App\\Services\\Reminders\\Api\\ContactVisibility';
    $app = dirname(__DIR__, 2).'/app';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS));
    $naming = [];

    foreach ($files as $file) {
        if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), $old)) {
            $naming[] = $file->getPathname();
        }
    }

    expect(class_exists('App\\Services\\ContactVisibility'))->toBeTrue()
        ->and(file_exists($app.'/Services/Reminders/Api/ContactVisibility.php'))->toBeFalse()
        ->and($naming)->toBe([]);
});
