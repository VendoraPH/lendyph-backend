<?php

use App\Models\OriginationApplication;
use App\Models\OriginationDocument;
use App\Models\OriginationDocumentVersion;
use App\Services\LoanOrigination\Files\ClamAvScanner;
use App\Services\LoanOrigination\Files\FileScanner;
use App\Services\LoanOrigination\Files\MisconfiguredScanner;
use App\Services\LoanOrigination\Files\NoScanner;
use App\Services\LoanOrigination\Files\OriginationFileStore;
use App\Services\LoanOrigination\Files\ScannerUnavailableException;
use App\Services\LoanOrigination\Files\ScanResult;
use App\Services\LoanOrigination\Files\UploadRefusedException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

function loPdf(string $body = 'BT /F1 12 Tf (Payslip) Tj ET', string $name = 'payslip.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n{$body}\n%%EOF\n");
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

/** A PDF whose one object is a stream with this dictionary and data. */
function loPdfStream(string $dictionary, string $data, string $tail = ''): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        'statement.pdf',
        "%PDF-1.5\n1 0 obj\n<< {$dictionary} /Length ".strlen($data)." >>\nstream\n{$data}\nendstream\nendobj\n{$tail}%%EOF\n",
    );
}

it('reads compressed streams and refuses active content inside them', function () {
    $application = OriginationApplication::query()->create();

    expect(fn () => loStore()->store($application, 'payslip', loPdfStream('/Type /ObjStm /Filter /FlateDecode', (string) gzcompress('<< /S /JavaScript /JS (app.alert(1)) >>'))))
        ->toThrow(UploadRefusedException::class, OriginationFileStore::ACTIVE_PDF);
});

it('keeps a plain compressed PDF and a photo inside one', function () {
    $application = OriginationApplication::query()->create();

    $page = loStore()->store($application, 'payslip', loPdfStream('/Filter /FlateDecode', (string) gzcompress('BT (Net pay 21,000) Tj ET')));
    $photo = loStore()->store($application, 'proof_of_income', loPdfStream('/Type /XObject /Subtype /Image /Filter /DCTDecode', "\xFF\xD8\xFF\xE0 not really a jpeg"));

    expect($page->exists)->toBeTrue()->and($photo->exists)->toBeTrue();
});

it('refuses a stream it can\'t decode, failing closed', function (string $dictionary, string $data) {
    $application = OriginationApplication::query()->create();

    expect(fn () => loStore()->store($application, 'payslip', loPdfStream($dictionary, $data)))
        ->toThrow(UploadRefusedException::class, OriginationFileStore::UNCHECKABLE_PDF);

    expect(Storage::disk('private')->allFiles())->toBe([]);
})->with([
    'ASCIIHex' => ['/Filter /ASCIIHexDecode', '3C3C202F4A53202878292029203E3E>'],
    'ASCII85' => ['/Filter /ASCII85Decode', '<~6W-?:+DGm>~>'],
    'LZW' => ['/Filter /LZWDecode', "\x80\x0B\x60\x50\x22\x0C\x0C\x85\x01"],
    'a chain of filters' => ['/Filter [/ASCII85Decode /FlateDecode]', '<~Gar~>'],
    'a hex-escaped filter name' => ['/Filter /ASCIIHex#44ecode', '2F4A53>'],
    'an image filter outside an image' => ['/Type /ObjStm /Filter /JPXDecode', "\x00\x00\x00\x0CjP  "],
    'a predictor on an object stream' => ['/Type /ObjStm /Filter /FlateDecode /DecodeParms << /Predictor 12 /Columns 4 >>', (string) gzcompress("\x02/JS ")],
    'a bad checksum' => ['/Type /ObjStm /Filter /FlateDecode', substr((string) gzcompress('<< /JS (app.alert(1)) >>'), 0, -1)."\x00"],
    'not compressed at all' => ['/Filter /FlateDecode', 'plain text, not deflate'],
]);

it('refuses a PDF whose streams would inflate past the budget, counting every attempt', function () {
    $application = OriginationApplication::query()->create();
    $bomb = (string) gzcompress(str_repeat("\0", OriginationFileStore::MAX_INFLATED_BYTES + 1024));

    expect(fn () => loStore()->store($application, 'payslip', loPdfStream('/Filter /FlateDecode', $bomb)))
        ->toThrow(UploadRefusedException::class, OriginationFileStore::PDF_TOO_LARGE);
});

it('refuses an encrypted PDF and one carrying an XFA form', function (string $tail, string $message) {
    $application = OriginationApplication::query()->create();

    expect(fn () => loStore()->store($application, 'payslip', loPdfStream('/Filter /FlateDecode', (string) gzcompress('BT ET'), $tail)))
        ->toThrow(UploadRefusedException::class, $message);
})->with([
    'encrypted' => ["trailer\n<< /Root 1 0 R /Encrypt 5 0 R >>\n", OriginationFileStore::ENCRYPTED_PDF],
    'xfa' => ["2 0 obj << /AcroForm << /XFA 7 0 R >> >> endobj\n", OriginationFileStore::ACTIVE_PDF],
]);

it('refuses the PDF when a pattern check itself fails, never reading it as clean', function () {
    $store = loStore();
    // A run of spaces after /Predictor makes the pattern backtrack once per
    // space, so a backtracking limit of 1 makes the check itself fail.
    $pdf = loPdfStream('/Filter /FlateDecode /DecodeParms << /Predictor'.str_repeat(' ', 2000).'/Columns 4 >>', (string) gzcompress('BT ET'))->getContent();

    expect($store->checkedPdf($pdf))->toBe($pdf);

    $limit = ini_get('pcre.backtrack_limit');
    $jit = ini_get('pcre.jit');
    $refusal = null;

    // The limit is lowered around the PDF check alone: the rest of the
    // framework uses the same pattern engine.
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');

    try {
        $store->checkedPdf($pdf);
    } catch (UploadRefusedException $e) {
        $refusal = $e;
    } finally {
        ini_set('pcre.backtrack_limit', (string) $limit);
        ini_set('pcre.jit', (string) $jit);
    }

    expect($refusal)->toBeInstanceOf(UploadRefusedException::class)
        ->and($refusal?->getMessage())->toBe(OriginationFileStore::UNCHECKABLE_PDF);
});

it('refuses pictures while the server can\'t re-encode them, and still takes PDFs', function () {
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
