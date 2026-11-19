<?php

use App\Models\OriginationApplication;
use App\Models\OriginationDocument;
use App\Models\OriginationDocumentVersion;
use App\Services\LoanOrigination\Files\ClamAvScanner;
use App\Services\LoanOrigination\Files\FileScanner;
use App\Services\LoanOrigination\Files\MisconfiguredScanner;
use App\Services\LoanOrigination\Files\NoScanner;
use App\Services\LoanOrigination\Files\OriginationFileStore;
use App\Services\LoanOrigination\Files\QpdfNormaliser;
use App\Services\LoanOrigination\Files\ScannerUnavailableException;
use App\Services\LoanOrigination\Files\ScanResult;
use App\Services\LoanOrigination\Files\UploadRefusedException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/*
 * LO-B1: the origination file store and its scanner seam (origination
 * decision 4). JPEG, PNG and PDF only, up to 10 MB, judged by both the bytes
 * and the name; PDFs carrying JavaScript, launch actions or embedded files
 * are refused; images are re-encoded; files go on the private disk. With no
 * scanner a file is kept as "Not scanned"; an infected file is refused, and
 * so is every file while a configured scanner can't be reached.
 */
uses(TestCase::class);

beforeEach(function () {
    Storage::fake('private');
});

function loPng(string $name = 'id.png', int $seed = 1): UploadedFile
{
    $image = imagecreatetruecolor(4, 4);
    imagefill($image, 0, 0, imagecolorallocate($image, $seed % 255, 10, 10));
    ob_start();
    imagepng($image);

    return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
}

/** A stream object's body: its dictionary with the length, then the data. */
function loStreamBody(string $dictionary, string $data, string $eol = "\n"): string
{
    return "<< {$dictionary} /Length ".strlen($data)." >>\nstream{$eol}{$data}\nendstream";
}

/**
 * A real PDF: the objects as written, the compressed ones (number => [object
 * stream, index]) and a cross-reference stream that finds them all, as a
 * PDF reader does.
 *
 * @param  array<int, string>  $objects
 * @param  array<int, array{0: int, 1: int}>  $compressed
 */
function loBuildPdf(array $objects, array $compressed = [], string $trailer = ''): string
{
    ksort($objects);
    $pdf = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];

    foreach ($objects as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
    }

    $xref = max([...array_keys($objects), ...array_keys($compressed)]) + 1;
    $offsets[$xref] = strlen($pdf);
    $entries = pack('CNn', 0, 0, 65535);

    for ($number = 1; $number <= $xref; $number++) {
        $entries .= match (true) {
            isset($offsets[$number]) => pack('CNn', 1, $offsets[$number], 0),
            isset($compressed[$number]) => pack('CNn', 2, $compressed[$number][0], $compressed[$number][1]),
            default => pack('CNn', 0, 0, 0),
        };
    }

    $size = $xref + 1;

    return $pdf."{$xref} 0 obj\n".loStreamBody("/Type /XRef /Size {$size} /W [1 4 2] /Root 1 0 R {$trailer}", $entries)
        ."\nendobj\nstartxref\n{$offsets[$xref]}\n%%EOF\n";
}

/** @return array<int, string> a one-page document */
function loPageObjects(string $catalog = '', string $contents = 'BT /F1 12 Tf (Payslip) Tj ET', string $page = ''): array
{
    return [
        1 => "<< /Type /Catalog /Pages 2 0 R {$catalog} >>",
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R {$page} >>",
        4 => loStreamBody('', $contents),
    ];
}

/** A one-page PDF, with $active (if any) reachable from the catalog. */
function loPdf(?string $active = null, string $name = 'payslip.pdf'): UploadedFile
{
    $objects = loPageObjects($active === null ? '' : '/Extra 5 0 R');

    if ($active !== null) {
        $objects[5] = $active;
    }

    return UploadedFile::fake()->createWithContent($name, loBuildPdf($objects));
}

/**
 * A PDF whose document JavaScript sits in an object stream: the catalog's
 * /Names points at objects 8 and 9, which live only inside object 10, the
 * way a real attacker hides them.
 *
 * @param  (Closure(string): string)|null  $encode  the object stream's encoding (deflate by default)
 * @param  array<int, string>  $extra
 */
function loHiddenJavaScript(string $dictionary, ?Closure $encode = null, string $eol = "\n", array $extra = []): UploadedFile
{
    $names = '<< /JavaScript << /Names [(a) 9 0 R] >> >>';
    $action = '<< /S /JavaScript /JS (app.alert\\(1\\)) >>';
    $header = '8 0 9 '.(strlen($names) + 1).' ';
    $data = ($encode ?? fn (string $plain) => (string) gzcompress($plain))($header.$names.' '.$action);

    $objects = loPageObjects('/Names 8 0 R') + $extra;
    $objects[10] = loStreamBody('/Type /ObjStm /N 2 /First '.strlen($header).' '.$dictionary, $data, $eol);

    return UploadedFile::fake()->createWithContent('statement.pdf', loBuildPdf($objects, [8 => [10, 0], 9 => [10, 1]]));
}

/** The active names in a PDF's text, `#xx` escapes decoded. */
function loActiveNames(string $pdf): array
{
    $decoded = (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn (array $m) => chr((int) hexdec($m[1])), $pdf);
    preg_match_all('~/(JavaScript|JS|OpenAction|AA|Launch|EmbeddedFiles?|XFA)(?![A-Za-z0-9])~', $decoded, $m);

    return array_values(array_unique($m[1]));
}

/** The PDF as qpdf reads it, every stream it can decode uncompressed. */
function loQpdfReading(string $pdf): string
{
    $dir = sys_get_temp_dir().'/lo-probe-'.bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    file_put_contents("{$dir}/in.pdf", $pdf);

    try {
        (new Process(['qpdf', '--object-streams=disable', '--stream-data=uncompress', '--decode-level=all', "{$dir}/in.pdf", "{$dir}/out.pdf"]))->run();

        return is_file("{$dir}/out.pdf") ? (string) file_get_contents("{$dir}/out.pdf") : '';
    } finally {
        @unlink("{$dir}/in.pdf");
        @unlink("{$dir}/out.pdf");
        @rmdir($dir);
    }
}

/** These tests run the real qpdf; without it they say so and skip. */
function loNeedsQpdf(): void
{
    if ((new ExecutableFinder)->find('qpdf') === null) {
        test()->markTestSkipped('qpdf is not installed in this test run (apt-get install qpdf); the PDF checks need it.');
    }
}

/** Either the file is refused, or what was stored carries no active content, as qpdf reads it too. */
function loNeverStoresActiveContent(UploadedFile $file): void
{
    $application = OriginationApplication::query()->create();

    try {
        $version = loStore()->store($application, 'payslip', $file);
    } catch (UploadRefusedException) {
        expect(Storage::disk('private')->allFiles())->toBe([]);

        return;
    }

    $stored = Storage::disk('private')->get($version->path);

    expect(loActiveNames($stored))->toBe([])
        ->and(loActiveNames(loQpdfReading($stored)))->toBe([])
        ->and($stored)->not->toBe($file->getContent());
}

/** ASCII85, as PDF's ASCII85Decode reads it. */
function loAscii85(string $bytes): string
{
    $pad = (4 - strlen($bytes) % 4) % 4;
    $groups = str_split($bytes.str_repeat("\0", $pad), 4);
    $out = '';

    foreach ($groups as $i => $group) {
        $n = unpack('N', $group)[1];

        if ($n === 0 && $i < count($groups) - 1) {
            $out .= 'z';

            continue;
        }

        $chars = '';
        for ($k = 0; $k < 5; $k++) {
            $chars = chr($n % 85 + 33).$chars;
            $n = intdiv($n, 85);
        }
        $out .= $chars;
    }

    return ($pad > 0 ? substr($out, 0, -$pad) : $out).'~>';
}

function loStore(): OriginationFileStore
{
    return app(OriginationFileStore::class);
}

function loScanner(ScanResult $result): void
{
    app()->instance(FileScanner::class, new class($result) implements FileScanner
    {
        public function __construct(private ScanResult $result) {}

        public function name(): string
        {
            return 'test';
        }

        public function scan(string $path): ScanResult
        {
            return $this->result;
        }
    });
}

it('uses no scanner by default, so files are kept as "Not scanned"', function () {
    expect(app(FileScanner::class))->toBeInstanceOf(NoScanner::class);

    $application = OriginationApplication::query()->create();
    $version = loStore()->store($application, 'government_id', loPng());

    expect($version->scan_status)->toBe('not_scanned')
        ->and($version->scanLabel())->toBe('Not scanned')
        ->and($version->disk)->toBe('private')
        ->and($version->mime_type)->toBe('image/png')
        ->and($version->sha256)->toHaveLength(64)
        ->and($version->public_id)->toHaveLength(26)
        ->and($version->version)->toBe(1);

    Storage::disk('private')->assertExists($version->path);
    expect($version->path)->toStartWith("loan-origination/{$application->id}/");

    $document = OriginationDocument::query()->sole();
    expect($document->type)->toBe('government_id')
        ->and($document->status)->toBe('uploaded')
        ->and($document->current_version_id)->toBe($version->id)
        ->and($document->public_id)->toHaveLength(26);
});

it('chooses the ClamAV driver from the config', function () {
    config(['loan_origination.scanner.driver' => 'clamav']);
    app()->forgetInstance(FileScanner::class);

    expect(app(FileScanner::class))->toBeInstanceOf(ClamAvScanner::class);
});

it('keeps a file a scanner passed as clean', function () {
    loNeedsQpdf();
    loScanner(ScanResult::Clean);
    $application = OriginationApplication::query()->create();

    $version = loStore()->store($application, 'payslip', loPdf());

    expect($version->scan_status)->toBe('clean')
        ->and($version->scanner)->toBe('test')
        ->and($version->scanned_at)->not->toBeNull()
        ->and($version->scanLabel())->toBe('Scanned, no threat found');
});

it('refuses an infected file and keeps nothing', function () {
    loScanner(ScanResult::Infected);
    $application = OriginationApplication::query()->create();

    expect(fn () => loStore()->store($application, 'payslip', loPdf()))
        ->toThrow(UploadRefusedException::class, 'This file failed the virus check, so it wasn\'t saved. Upload a different file.');

    expect(OriginationDocumentVersion::query()->count())->toBe(0)
        ->and(OriginationDocument::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('refuses every file while a configured scanner can\'t be reached', function () {
    loScanner(ScanResult::Failed);
    $application = OriginationApplication::query()->create();

    try {
        loStore()->store($application, 'payslip', loPdf());
        $this->fail('The file was kept unchecked.');
    } catch (ScannerUnavailableException $e) {
        expect($e->getMessage())->toBe('Files can\'t be checked for viruses right now. Try again in a few minutes.')
            ->and($e->render()->getStatusCode())->toBe(503);
    }

    expect(Storage::disk('private')->allFiles())->toBe([]);
});

it('adds a new version for a new file of the same type, and returns the same version for the same file again', function () {
    $application = OriginationApplication::query()->create();

    $first = loStore()->store($application, 'government_id', loPng(seed: 1));
    $again = loStore()->store($application, 'government_id', loPng('again.png', seed: 1));
    $second = loStore()->store($application, 'government_id', loPng('new.png', seed: 2));

    expect($again->id)->toBe($first->id)
        ->and($second->version)->toBe(2)
        ->and(OriginationDocumentVersion::query()->count())->toBe(2)
        ->and(OriginationDocument::query()->sole()->current_version_id)->toBe($second->id);
});

it('refuses a PDF carrying JavaScript, a launch action or an embedded file', function (string $body) {
    loNeedsQpdf();
    $application = OriginationApplication::query()->create();

    expect(fn () => loStore()->store($application, 'payslip', loPdf($body)))
        ->toThrow(UploadRefusedException::class, 'This PDF contains scripts, actions or attached files, so it wasn\'t saved. Save it again as a plain PDF and upload that.');
})->with([
    'javascript' => '<< /S /JavaScript /JS (app.alert(1)) >>',
    'js short' => '<< /JS (x) >>',
    'launch' => '<< /S /Launch /F (cmd.exe) >>',
    'embedded file' => '<< /Type /EmbeddedFile >>',
    'hex-escaped name' => '<< /S /Java#53cript >>',
]);

it('refuses other types, a name that doesn\'t match the bytes, and files over 10 MB, in words', function (Closure $file, string $message) {
    $application = OriginationApplication::query()->create();

    expect(fn () => loStore()->store($application, 'payslip', $file()))->toThrow(UploadRefusedException::class, $message);
})->with([
    'word file' => [fn () => UploadedFile::fake()->create('cv.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'), 'Upload a JPEG, PNG or PDF file.'],
    'pdf named png' => [fn () => loPdf(name: 'photo.png'), 'Upload a JPEG, PNG or PDF file.'],
    'png named exe' => [fn () => loPng('id.exe'), 'Upload a JPEG, PNG or PDF file.'],
    'too large' => [fn () => UploadedFile::fake()->createWithContent('big.pdf', "%PDF-1.4\n".str_repeat('a', 10 * 1024 * 1024)), 'A file can be at most 10 MB.'],
]);

it('takes at most 20 files per application', function () {
    $application = OriginationApplication::query()->create();

    for ($i = 1; $i <= 20; $i++) {
        loStore()->store($application, 'other', loPng("f{$i}.png", seed: $i));
    }

    expect(fn () => loStore()->store($application, 'other', loPng('f21.png', seed: 21)))
        ->toThrow(UploadRefusedException::class, 'An application can hold at most 20 files.');
});

it('re-encodes images, so nothing but the picture is kept', function () {
    $application = OriginationApplication::query()->create();
    $png = loPng();
    $withTrailer = UploadedFile::fake()->createWithContent('id.png', $png->getContent().'<?php echo 1; ?>');

    $version = loStore()->store($application, 'government_id', $withTrailer);

    expect(Storage::disk('private')->get($version->path))->not->toContain('<?php')
        ->and($version->file_name)->toBe('id.png');
});

it('keeps a clean file name: no path, no control characters', function () {
    loNeedsQpdf();
    $application = OriginationApplication::query()->create();

    $version = loStore()->store($application, 'payslip', loPdf(name: "../../etc/pay\x07slip.pdf"));

    expect($version->file_name)->toBe('payslip.pdf');
});

it('speaks the clamd INSTREAM protocol and reads its three answers', function (string $answer, ScanResult $expected) {
    [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    fwrite($server, $answer);

    $path = tempnam(sys_get_temp_dir(), 'lo-scan');
    file_put_contents($path, 'hello');

    $scanner = new ClamAvScanner(fn () => $client, chunkBytes: 2);
    $result = $scanner->scan($path);
    unlink($path);

    $sent = stream_get_contents($server);

    expect($result)->toBe($expected)
        ->and($sent)->toBe("zINSTREAM\0".pack('N', 2).'he'.pack('N', 2).'ll'.pack('N', 1).'o'.pack('N', 0));
})->with([
    'clean' => ["stream: OK\0", ScanResult::Clean],
    'infected' => ["stream: Eicar-Test-Signature FOUND\0", ScanResult::Infected],
    'error' => ["INSTREAM size limit exceeded. ERROR\0", ScanResult::Failed],
]);

it('reads an unreachable clamd as a failed scan, never as clean', function () {
    $scanner = new ClamAvScanner(fn () => false);
    $path = tempnam(sys_get_temp_dir(), 'lo-scan');

    expect($scanner->scan($path))->toBe(ScanResult::Failed);
    unlink($path);
});

it('never stores JavaScript hidden in an object stream, however its stream is dressed up', function (string $dictionary, ?Closure $encode, string $eol, array $extra) {
    loNeedsQpdf();

    loNeverStoresActiveContent(loHiddenJavaScript($dictionary, $encode, $eol, $extra));
})->with([
    'plain deflate' => ['/Filter /FlateDecode', null, "\n", []],
    'an image marker in a nested dictionary' => ['/X << /Filter /DCTDecode /Subtype /Image >> /Filter /FlateDecode', null, "\n", []],
    'a cross-reference type in a nested dictionary' => ['/X << /Type /XRef >> /Filter /FlateDecode', null, "\n", []],
    'a comment after /Filter' => ["/Filter %not the filter\n/FlateDecode", null, "\n", []],
    'an indirect filter' => ['/Filter 11 0 R', null, "\n", [11 => '/FlateDecode']],
    'stream then a space' => ['/Filter /FlateDecode', null, ' ', []],
    'stream then a lone CR' => ['/Filter /FlateDecode', null, "\r", []],
    'ASCIIHex' => ['/Filter /ASCIIHexDecode', fn (string $plain) => bin2hex($plain).'>', "\n", []],
    'ASCII85' => ['/Filter /ASCII85Decode', fn (string $plain) => loAscii85($plain), "\n", []],
    'a chain of filters' => ['/Filter [/ASCIIHexDecode /FlateDecode]', fn (string $plain) => bin2hex((string) gzcompress($plain)).'>', "\n", []],
    'a hex-escaped filter name' => ['/Filter /Fl#61teDecode', null, "\n", []],
    'a bad checksum' => ['/Filter /FlateDecode', fn (string $plain) => substr((string) gzcompress($plain), 0, -1)."\x00", "\n", []],
]);

it('refuses JavaScript once qpdf has opened up the object streams', function () {
    loNeedsQpdf();
    $application = OriginationApplication::query()->create();

    expect(fn () => loStore()->store($application, 'payslip', loHiddenJavaScript('/Filter /FlateDecode')))
        ->toThrow(UploadRefusedException::class, OriginationFileStore::ACTIVE_PDF);
});

it('stores qpdf\'s own copy of a plain PDF, never the file as sent, pictures inside included', function () {
    loNeedsQpdf();
    $application = OriginationApplication::query()->create();
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagejpeg($image);
    $jpeg = (string) ob_get_clean();

    $objects = loPageObjects(contents: '', page: '/Resources << /XObject << /Im1 5 0 R >> >>');
    $objects[4] = loStreamBody('/Filter /FlateDecode', (string) gzcompress('q 100 0 0 100 0 0 cm /Im1 Do Q BT (Net pay 21,000) Tj ET'));
    $objects[5] = loStreamBody('/Type /XObject /Subtype /Image /Width 2 /Height 2 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode', $jpeg);
    $file = UploadedFile::fake()->createWithContent('payslip.pdf', loBuildPdf($objects));

    $version = loStore()->store($application, 'payslip', $file);
    $stored = Storage::disk('private')->get($version->path);

    expect($stored)->toStartWith('%PDF-')
        ->and($stored)->not->toBe($file->getContent())
        ->and(loQpdfReading($stored))->toContain('Net pay 21,000')
        ->and($version->sha256)->toBe(hash('sha256', $file->getContent()));
});

it('refuses a PDF that would open up past the size it can check', function () {
    loNeedsQpdf();
    $application = OriginationApplication::query()->create();
    $objects = loPageObjects();
    $objects[4] = loStreamBody('/Filter /FlateDecode', (string) gzcompress(str_repeat(' ', OriginationFileStore::MAX_NORMALISED_BYTES + 1024)));

    expect(fn () => loStore()->store($application, 'payslip', UploadedFile::fake()->createWithContent('big.pdf', loBuildPdf($objects))))
        ->toThrow(UploadRefusedException::class, OriginationFileStore::PDF_TOO_LARGE);

    expect(Storage::disk('private')->allFiles())->toBe([]);
});

it('refuses an encrypted PDF, an XFA form and actions that run on opening', function (string $catalog, string $trailer, array $extra, string $message) {
    loNeedsQpdf();
    $application = OriginationApplication::query()->create();
    $file = UploadedFile::fake()->createWithContent('form.pdf', loBuildPdf(loPageObjects($catalog) + $extra, [], $trailer));

    expect(fn () => loStore()->store($application, 'payslip', $file))->toThrow(UploadRefusedException::class, $message);
})->with([
    'encrypted' => ['', '/Encrypt 6 0 R /ID [<00> <00>]', [6 => '<< /Filter /Standard /V 1 /R 2 /O <00> /U <00> /P -4 >>'], OriginationFileStore::ENCRYPTED_PDF],
    'xfa' => ['/AcroForm << /Fields [] /XFA 7 0 R >>', '', [7 => '<< >>'], OriginationFileStore::ACTIVE_PDF],
    'open action' => ['/OpenAction 7 0 R', '', [7 => '<< /S /GoTo /D [3 0 R /Fit] >>'], OriginationFileStore::ACTIVE_PDF],
    'additional actions' => ['/AA << /WC 7 0 R >>', '', [7 => '<< /S /GoTo /D [3 0 R /Fit] >>'], OriginationFileStore::ACTIVE_PDF],
]);

it('refuses PDFs while qpdf is missing or failing, in words, and keeps nothing', function () {
    $application = OriginationApplication::query()->create();
    $missing = new OriginationFileStore(new NoScanner, pdf: new QpdfNormaliser('/nonexistent/qpdf'));
    $failing = new OriginationFileStore(new NoScanner, pdf: new QpdfNormaliser('/bin/false'));

    expect(fn () => $missing->store($application, 'payslip', loPdf()))->toThrow(UploadRefusedException::class, OriginationFileStore::PDFS_UNAVAILABLE)
        ->and(fn () => $failing->store($application, 'payslip', loPdf()))->toThrow(UploadRefusedException::class, OriginationFileStore::UNCHECKABLE_PDF)
        ->and(Storage::disk('private')->allFiles())->toBe([])
        ->and($missing->store($application, 'government_id', loPng())->exists)->toBeTrue();
});

it('reads the qpdf path from the config, with plain qpdf by default', function () {
    expect(File::get(config_path('loan_origination.php')))->toContain("'binary' => env('LOAN_ORIGINATION_QPDF_BINARY', 'qpdf')");

    config(['loan_origination.qpdf.binary' => '/opt/qpdf/bin/qpdf']);
    expect((new QpdfNormaliser)->binary())->toBe('/opt/qpdf/bin/qpdf');
});

it('refuses the PDF when a pattern check itself fails, never reading it as clean', function () {
    $store = loStore();
    $store->assertNothingActive(str_repeat('/JSa ', 2000));

    // The store's matcher, given a pattern that runs out of backtracking at
    // the default limit: a failed check refuses, never passes.
    $matches = new ReflectionMethod(OriginationFileStore::class, 'matches');

    expect(fn () => $matches->invoke($store, '/(a+)+$/', str_repeat('a', 40).'b'))
        ->toThrow(UploadRefusedException::class, OriginationFileStore::UNCHECKABLE_PDF);
});

it('refuses pictures while the server can\'t re-encode them, and still takes PDFs', function () {
    loNeedsQpdf();
    $store = new OriginationFileStore(new NoScanner, gdAvailable: false);
    $application = OriginationApplication::query()->create();

    expect(fn () => $store->store($application, 'government_id', loPng()))
        ->toThrow(UploadRefusedException::class, OriginationFileStore::PICTURES_UNAVAILABLE);

    expect(Storage::disk('private')->allFiles())->toBe([])
        ->and($store->store($application, 'payslip', loPdf())->exists)->toBeTrue();
});

it('refuses a picture above 24 megapixels before decoding it', function () {
    $application = OriginationApplication::query()->create();
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $png = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 6000, 4100, 8, 2, 0, 0, 0)).$chunk('IEND', '');

    expect(OriginationFileStore::MAX_IMAGE_PIXELS)->toBe(24_000_000)
        ->and(fn () => loStore()->store($application, 'government_id', UploadedFile::fake()->createWithContent('id.png', $png)))
        ->toThrow(UploadRefusedException::class, OriginationFileStore::IMAGE_TOO_LARGE);
});

it('reads the scanner setting whatever its case or spaces, and refuses uploads with 503 on an unknown one', function () {
    config(['loan_origination.scanner.driver' => ' ClamAV ']);
    app()->forgetInstance(FileScanner::class);
    expect(app(FileScanner::class))->toBeInstanceOf(ClamAvScanner::class);

    config(['loan_origination.scanner.driver' => 'NONE']);
    app()->forgetInstance(FileScanner::class);
    expect(app(FileScanner::class))->toBeInstanceOf(NoScanner::class);

    config(['loan_origination.scanner.driver' => 'clamd']);
    app()->forgetInstance(FileScanner::class);
    expect(app(FileScanner::class))->toBeInstanceOf(MisconfiguredScanner::class);

    $application = OriginationApplication::query()->create();

    try {
        loStore()->store($application, 'payslip', loPdf());
        $this->fail('A file was kept with no recognised scanner.');
    } catch (ScannerUnavailableException $e) {
        expect($e->render()->getStatusCode())->toBe(503)
            ->and($e->getMessage())->toBe(ScannerUnavailableException::MISCONFIGURED);
    }

    expect(Storage::disk('private')->allFiles())->toBe([]);
});
