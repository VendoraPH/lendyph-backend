<?php

use App\Services\Cic\File\CicEncryptor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cic\Submissions\CicSubmissionsTestHelpers;
use Tests\TestCase;

uses(TestCase::class, CicSubmissionsTestHelpers::class);

/*
 * Review S-L3: no plain file outlives a request. The TXT goes into the ZIP
 * from memory, so it never touches disk; each run removes its folder (and
 * registers a shutdown cleanup), and the hourly cic:clear-temp removes the
 * folders a killed run left, once over an hour old, never following a link.
 */

beforeEach(function () {
    $this->setUpCic();
    Storage::fake('private');
});

afterEach(function () {
    $this->tearDownTestKeys();
});

it('never writes the plain TXT to disk while encrypting', function () {
    $key = $this->makeTestKey();
    $this->activateTestKey($key);
    $user = $this->cicAll();
    $capture = $this->simpleCapture();
    $id = $this->actingAs($user)->postJson('/api/cic/submissions', ['capture_id' => $capture->id, 'submission_type' => 0])->assertCreated()->json('data.id');

    // A gpg wrapper that lists the run's folder each time gpg is called.
    $bin = $key['home'].'/bin';
    mkdir($bin, 0700);
    $log = $key['home'].'/listing.log';
    file_put_contents($bin.'/gpg', "#!/bin/sh\nls -A \"\$PWD\" >> ".escapeshellarg($log)."\nexec ".escapeshellarg((string) config('cic.gpg_binary'))." \"\$@\"\n");
    chmod($bin.'/gpg', 0700);
    config(['cic.gpg_binary' => $bin.'/gpg']);

    $this->actingAs($user)->postJson("/api/cic/submissions/{$id}/encrypt", ['confirm' => true])->assertOk()->assertJsonPath('data.status', 'encrypted');

    $listing = (string) file_get_contents($log);
    expect($listing)->toContain('.zip')
        ->and($listing)->not->toContain('.txt')
        ->and($this->leftoverEncryptionFolders())->toBe([]);
});

it('sweeps stale encryption folders hourly, leaving fresh ones and links', function () {
    $root = storage_path('app/cic-tmp');
    @mkdir($root, 0700, true);
    $stale = $root.'/'.CicEncryptor::FOLDER_PREFIX.'stale0000';
    $fresh = $root.'/'.CicEncryptor::FOLDER_PREFIX.'fresh0000';
    $other = $root.'/not-ours';
    $target = sys_get_temp_dir().'/cic-sweep-target-'.bin2hex(random_bytes(4));
    $link = $root.'/'.CicEncryptor::FOLDER_PREFIX.'link0000';

    foreach ([$stale, $fresh, $other, $target] as $folder) {
        mkdir($folder, 0700);
        file_put_contents($folder.'/file', 'x');
    }

    symlink($target, $link);
    touch($stale, time() - 7200);
    touch($other, time() - 7200);

    try {
        $this->artisan('cic:clear-temp')->expectsOutput('Removed 1 stale CIC temporary folder.')->assertSuccessful();

        expect(is_dir($stale))->toBeFalse()
            ->and(is_dir($fresh))->toBeTrue()
            ->and(is_dir($other))->toBeTrue()
            ->and(is_file($target.'/file'))->toBeTrue();

        $this->artisan('cic:clear-temp')->expectsOutput('No stale CIC temporary folders.')->assertSuccessful();
    } finally {
        @unlink($link);

        foreach ([$fresh, $other, $target] as $folder) {
            @unlink($folder.'/file');
            @rmdir($folder);
        }
    }

    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains((string) $event->command, 'cic:clear-temp'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 * * * *');
});
