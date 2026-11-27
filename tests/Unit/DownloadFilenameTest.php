<?php

use App\Services\DownloadFilename;

/**
 * The ASCII fallback of a download name never holds a path separator, even
 * one that only appears once the name is spelt in ASCII, and falls back to
 * the stored file's name when nothing but an extension would be left.
 *
 * No database, no application boot.
 */
it('replaces path separators in the fallback', function (string $name, string $fallback) {
    expect(DownloadFilename::fallback($name, 'abc123.pdf'))->toBe($fallback);
})->with([
    'a slash' => ['half/full.pdf', 'half_full.pdf'],
    'a backslash' => ['C:\\scan.pdf', 'C:_scan.pdf'],
    'a percent sign' => ['100%.pdf', '100.pdf'],
    'only an extension left' => ["\u{1F600}.pdf", 'abc123.pdf'],
]);

it('never builds a header Symfony refuses from a fallback holding a separator', function () {
    expect(DownloadFilename::inlineDisposition('a/b\\c.pdf', 'documents/x/abc123.pdf'))->toBe('inline; filename=a_b_c.pdf');
});
