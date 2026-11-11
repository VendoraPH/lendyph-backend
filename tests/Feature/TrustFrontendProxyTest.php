<?php

use App\Http\Middleware\TrustFrontendProxy;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

/** The frontend box as the API sees it: one address for every visitor. */
const PX_FRONTEND_ADDR = '192.0.2.50';

/** The visitor's own address, appended by the frontend's nginx. */
const PX_VISITOR_ADDR = '203.0.113.10';

beforeEach(function () {
    Cache::flush();

    $this->secret = Str::random(64);
    config()->set('trustedproxy.proxy_secret', $this->secret);

    // A probe that reports what the app sees once the global middleware ran,
    // including whether the proxy header survived anywhere a logger could read.
    Route::middleware('api')->get('/api/__proxy-probe', function (Request $request) {
        return response()->json([
            'ip' => $request->ip(),
            'host' => $request->getHost(),
            'secure' => $request->isSecure(),
            'header' => $request->headers->has(TrustFrontendProxy::HEADER),
            'server' => $request->server->has('HTTP_X_LENDYPH_PROXY'),
            'globals' => array_key_exists('HTTP_X_LENDYPH_PROXY', $_SERVER),
        ]);
    });

    // A careless logger that dumps every header and server value it can see.
    Route::middleware('api')->get('/api/__proxy-log', function (Request $request) {
        Log::info('request dump', [
            'headers' => $request->headers->all(),
            'server' => $request->server->all(),
            'globals' => $_SERVER,
        ]);

        return response()->json(['ok' => true]);
    });
});

afterEach(function () {
    unset($_SERVER['HTTP_X_LENDYPH_PROXY']);
});

/**
 * @param  array<string, string>  $headers
 * @return array<string, mixed>
 */
function probeFrom(TestCase $test, string $remoteAddr, array $headers): array
{
    return $test->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
        ->getJson('/api/__proxy-probe', $headers)
        ->assertOk()
        ->json();
}

it('uses the visitor address when the frontend sends the right secret', function () {
    $seen = probeFrom($this, PX_FRONTEND_ADDR, [
        TrustFrontendProxy::HEADER => $this->secret,
        'X-Forwarded-For' => PX_VISITOR_ADDR,
    ]);

    expect($seen['ip'])->toBe(PX_VISITOR_ADDR);
});

it('keeps the rightmost address, so a browser-supplied X-Forwarded-For is ignored', function () {
    // The frontend's nginx appends the real peer after whatever the browser sent.
    $seen = probeFrom($this, PX_FRONTEND_ADDR, [
        TrustFrontendProxy::HEADER => $this->secret,
        'X-Forwarded-For' => '6.6.6.6, '.PX_VISITOR_ADDR,
    ]);

    expect($seen['ip'])->toBe(PX_VISITOR_ADDR);
});

it('trusts X-Forwarded-For only, never the forwarded host or scheme', function () {
    $seen = probeFrom($this, PX_FRONTEND_ADDR, [
        TrustFrontendProxy::HEADER => $this->secret,
        'X-Forwarded-For' => PX_VISITOR_ADDR,
        'X-Forwarded-Host' => 'evil.example',
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Port' => '8443',
    ]);

    expect($seen['ip'])->toBe(PX_VISITOR_ADDR)
        ->and($seen['host'])->not->toBe('evil.example')
        ->and($seen['secure'])->toBeFalse();
});

it('uses REMOTE_ADDR when the secret is wrong', function () {
    $seen = probeFrom($this, PX_FRONTEND_ADDR, [
        TrustFrontendProxy::HEADER => Str::random(64),
        'X-Forwarded-For' => PX_VISITOR_ADDR,
    ]);

    expect($seen['ip'])->toBe(PX_FRONTEND_ADDR);
});

it('uses REMOTE_ADDR when the secret is a prefix of the real one', function () {
    $seen = probeFrom($this, PX_FRONTEND_ADDR, [
        TrustFrontendProxy::HEADER => substr($this->secret, 0, 63),
        'X-Forwarded-For' => PX_VISITOR_ADDR,
    ]);

    expect($seen['ip'])->toBe(PX_FRONTEND_ADDR);
});

it('uses REMOTE_ADDR when no secret is sent', function () {
    $seen = probeFrom($this, PX_FRONTEND_ADDR, ['X-Forwarded-For' => PX_VISITOR_ADDR]);

    expect($seen['ip'])->toBe(PX_FRONTEND_ADDR);
});

it('uses REMOTE_ADDR when the secret is unset, whatever the header says', function (string $configured, string $sent) {
    config()->set('trustedproxy.proxy_secret', $configured);

    $seen = probeFrom($this, PX_FRONTEND_ADDR, [
        TrustFrontendProxy::HEADER => $sent,
        'X-Forwarded-For' => PX_VISITOR_ADDR,
    ]);

    expect($seen['ip'])->toBe(PX_FRONTEND_ADDR);
})->with([
    'empty secret, empty header' => ['', ''],
    'empty secret, any header' => ['', 'anything'],
    'short secret, matching header' => ['short-secret', 'short-secret'],
    '31 characters, matching header' => [str_repeat('a', 31), str_repeat('a', 31)],
]);

it('reads the secret from LENDYPH_PROXY_SECRET and resolves unset to empty', function () {
    $previous = getenv('LENDYPH_PROXY_SECRET');

    try {
        putenv('LENDYPH_PROXY_SECRET');
        unset($_ENV['LENDYPH_PROXY_SECRET'], $_SERVER['LENDYPH_PROXY_SECRET']);
        expect((require __DIR__.'/../../config/trustedproxy.php')['proxy_secret'])->toBe('');

        putenv('LENDYPH_PROXY_SECRET= abc123 ');
        $_ENV['LENDYPH_PROXY_SECRET'] = $_SERVER['LENDYPH_PROXY_SECRET'] = ' abc123 ';
        expect((require __DIR__.'/../../config/trustedproxy.php')['proxy_secret'])->toBe('abc123');
    } finally {
        unset($_ENV['LENDYPH_PROXY_SECRET'], $_SERVER['LENDYPH_PROXY_SECRET']);
        putenv($previous === false ? 'LENDYPH_PROXY_SECRET' : "LENDYPH_PROXY_SECRET={$previous}");
    }
});

it('accepts the secret from a listed source address', function () {
    config()->set('trustedproxy.proxy_sources', ['198.51.100.1', PX_FRONTEND_ADDR]);

    $seen = probeFrom($this, PX_FRONTEND_ADDR, [
        TrustFrontendProxy::HEADER => $this->secret,
        'X-Forwarded-For' => PX_VISITOR_ADDR,
    ]);

    expect($seen['ip'])->toBe(PX_VISITOR_ADDR);
});

it('refuses the right secret from an address that is not listed', function () {
    config()->set('trustedproxy.proxy_sources', ['198.51.100.1']);

    $seen = probeFrom($this, PX_FRONTEND_ADDR, [
        TrustFrontendProxy::HEADER => $this->secret,
        'X-Forwarded-For' => PX_VISITOR_ADDR,
    ]);

    expect($seen['ip'])->toBe(PX_FRONTEND_ADDR)
        ->and($seen['header'])->toBeFalse();
});

it('accepts any source while the list is empty', function () {
    config()->set('trustedproxy.proxy_sources', []);

    $seen = probeFrom($this, '198.51.100.99', [
        TrustFrontendProxy::HEADER => $this->secret,
        'X-Forwarded-For' => PX_VISITOR_ADDR,
    ]);

    expect($seen['ip'])->toBe(PX_VISITOR_ADDR);
});

it('matches a listed IPv6 source in any notation', function () {
    config()->set('trustedproxy.proxy_sources', ['2001:db8::1']);

    $seen = probeFrom($this, '2001:0db8:0000:0000:0000:0000:0000:0001', [
        TrustFrontendProxy::HEADER => $this->secret,
        'X-Forwarded-For' => PX_VISITOR_ADDR,
    ]);

    expect($seen['ip'])->toBe(PX_VISITOR_ADDR);
});

it('fails closed on a list that holds only a mistyped entry', function () {
    config()->set('trustedproxy.proxy_sources', ['192.0.2.500']);

    $seen = probeFrom($this, PX_FRONTEND_ADDR, [
        TrustFrontendProxy::HEADER => $this->secret,
        'X-Forwarded-For' => PX_VISITOR_ADDR,
    ]);

    expect($seen['ip'])->toBe(PX_FRONTEND_ADDR);
});

it('reads the source list from LENDYPH_PROXY_SOURCES and resolves unset to empty', function () {
    $previous = getenv('LENDYPH_PROXY_SOURCES');
    $sourcesFor = function (?string $value): array {
        if ($value === null) {
            putenv('LENDYPH_PROXY_SOURCES');
            unset($_ENV['LENDYPH_PROXY_SOURCES'], $_SERVER['LENDYPH_PROXY_SOURCES']);
        } else {
            putenv("LENDYPH_PROXY_SOURCES={$value}");
            $_ENV['LENDYPH_PROXY_SOURCES'] = $_SERVER['LENDYPH_PROXY_SOURCES'] = $value;
        }

        return (require __DIR__.'/../../config/trustedproxy.php')['proxy_sources'];
    };

    try {
        expect($sourcesFor(null))->toBe([])
            ->and($sourcesFor(''))->toBe([])
            ->and($sourcesFor(' 192.0.2.50 , ,2001:db8::1 '))->toBe(['192.0.2.50', '2001:db8::1'])
            ->and($sourcesFor('192.0.2.500'))->toBe(['192.0.2.500']);
    } finally {
        unset($_ENV['LENDYPH_PROXY_SOURCES'], $_SERVER['LENDYPH_PROXY_SOURCES']);
        putenv($previous === false ? 'LENDYPH_PROXY_SOURCES' : "LENDYPH_PROXY_SOURCES={$previous}");
    }
});

it('ignores a forged X-Forwarded-For from a direct caller', function () {
    $seen = probeFrom($this, '198.51.100.7', ['X-Forwarded-For' => '6.6.6.6']);

    expect($seen['ip'])->toBe('198.51.100.7');
});

it('does not carry trust over from a proven request to the next one', function () {
    $trusted = probeFrom($this, PX_FRONTEND_ADDR, [
        TrustFrontendProxy::HEADER => $this->secret,
        'X-Forwarded-For' => PX_VISITOR_ADDR,
    ]);

    $direct = probeFrom($this, PX_FRONTEND_ADDR, ['X-Forwarded-For' => '6.6.6.6']);

    expect($trusted['ip'])->toBe(PX_VISITOR_ADDR)
        ->and($direct['ip'])->toBe(PX_FRONTEND_ADDR);
});

it('keeps TRUSTED_PROXIES working as before when the header is absent', function () {
    config()->set('trustedproxy.proxies', ['10.0.0.9']);

    $seen = probeFrom($this, '10.0.0.9', ['X-Forwarded-For' => PX_VISITOR_ADDR]);

    expect($seen['ip'])->toBe(PX_VISITOR_ADDR);
});

it('removes the header before the app sees it, right or wrong', function (bool $right) {
    $_SERVER['HTTP_X_LENDYPH_PROXY'] = 'left over from the web server';

    $seen = probeFrom($this, PX_FRONTEND_ADDR, [
        TrustFrontendProxy::HEADER => $right ? $this->secret : 'wrong',
        'X-Forwarded-For' => PX_VISITOR_ADDR,
    ]);

    expect($seen['header'])->toBeFalse()
        ->and($seen['server'])->toBeFalse()
        ->and($seen['globals'])->toBeFalse();
})->with(['right secret' => true, 'wrong secret' => false]);

it('never lets the secret reach a log line', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = json_encode([$event->message, $event->context]);
    });

    $this->withServerVariables(['REMOTE_ADDR' => PX_FRONTEND_ADDR])
        ->getJson('/api/__proxy-log', [
            TrustFrontendProxy::HEADER => $this->secret,
            'X-Forwarded-For' => PX_VISITOR_ADDR,
        ])
        ->assertOk();

    expect($logged)->not->toBeEmpty();

    foreach ($logged as $line) {
        expect($line)->not->toContain($this->secret);
    }
});

it('records the visitor address, and never the secret, on the login audit row', function () {
    $this->withServerVariables(['REMOTE_ADDR' => PX_FRONTEND_ADDR])
        ->postJson('/api/auth/login', ['login' => 'super_admin', 'password' => 'password'], [
            TrustFrontendProxy::HEADER => $this->secret,
            'X-Forwarded-For' => PX_VISITOR_ADDR,
        ])
        ->assertOk();

    $row = AuditLog::where('action', 'login')->latest('id')->firstOrFail();

    expect($row->ip_address)->toBe(PX_VISITOR_ADDR);

    foreach (AuditLog::all() as $audit) {
        expect(json_encode($audit->getAttributes()))->not->toContain($this->secret);
    }
});

it('records the frontend address on the login audit row without the secret', function () {
    $this->withServerVariables(['REMOTE_ADDR' => PX_FRONTEND_ADDR])
        ->postJson('/api/auth/login', ['login' => 'super_admin', 'password' => 'password'], [
            'X-Forwarded-For' => PX_VISITOR_ADDR,
        ])
        ->assertOk();

    expect(AuditLog::where('action', 'login')->latest('id')->firstOrFail()->ip_address)
        ->toBe(PX_FRONTEND_ADDR);
});
