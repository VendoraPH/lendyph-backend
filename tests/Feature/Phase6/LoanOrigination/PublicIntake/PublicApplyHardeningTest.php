<?php

use App\Models\OriginationApplication;
use App\Models\OriginationSetting;
use App\Services\LoanOrigination\Intake\PublicPortal;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Feature\Phase6\LoanOrigination\PublicIntake\PublicApplyHelpers;
use Tests\TestCase;

/*
 * LO-B2 security review follow-ups: the estimate's size cap, closed links
 * ending intake sessions, per-IP limits behind every session-keyed limiter,
 * the switch answering 404 before any limiter, a daily cap that counts only
 * codes that reached someone plus one per mobile, test codes on test links
 * only, and links closed while the server can't tell visitors apart.
 */
uses(TestCase::class, PublicApplyHelpers::class);

/*
 * The daily code caps count from Manila midnight, and some tests travel 61
 * seconds past the one-a-minute code limit. Each test starts at mid-day
 * Manila, so a run near midnight can't carry a test into the next day (one
 * started at 23:59:51 and failed).
 */
beforeEach(fn () => $this->travelTo(now('Asia/Manila')->setTime(12, 0)));

afterEach(fn () => Carbon::setTestNow());

it('refuses an estimate with more instalments than the calculator allows, and caps the term', function () {
    $link = $this->enableIntake();
    $product = $this->onlineProduct(['min_term' => null, 'max_term' => null, 'term' => 5000, 'term_unit' => 'days', 'frequencies' => ['daily'], 'frequency' => 'daily']);
    $link->loanProducts()->sync([$product->id]);
    $session = $this->startApplication($link);
    $url = "/api/public-apply/{$link->token}/estimate";
    $base = ['loan_product_id' => $product->id, 'amount' => 20000, 'frequency' => 'daily'];

    $this->postJson($url, [...$base, 'term' => 2000], $this->applicantSession($session))->assertUnprocessable()
        ->assertJsonValidationErrors(['term' => 'This term and payment schedule make 2,000 payments; an estimate allows at most 1,000.']);
    $this->postJson($url, [...$base, 'term' => 10951], $this->applicantSession($session))->assertUnprocessable()->assertJsonValidationErrors('term');
    $this->postJson($url, [...$base, 'term' => 30], $this->applicantSession($session))->assertOk()->assertJsonPath('data.installments_count', 30);
});

it('caps a months term at 360', function () {
    $link = $this->enableIntake();
    $product = $this->onlineProduct(['min_term' => null, 'max_term' => null, 'term' => 400]);
    $link->loanProducts()->sync([$product->id]);
    $session = $this->startApplication($link);

    $this->postJson("/api/public-apply/{$link->token}/estimate", ['loan_product_id' => $product->id, 'amount' => 20000, 'term' => 361, 'frequency' => 'monthly'], $this->applicantSession($session))
        ->assertUnprocessable()->assertJsonValidationErrors('term');
});

it('refuses an open intake session once its link is turned off, and a resume on a closed link', function () {
    $link = $this->enableIntake();
    $session = $this->startApplication($link);
    $link->forceFill(['active' => false])->save();

    foreach ([
        fn () => $this->getJson("/api/public-apply/{$link->token}/draft", $this->applicantSession($session)),
        fn () => $this->putJson("/api/public-apply/{$link->token}/draft", ['draft' => []], $this->applicantSession($session)),
        fn () => $this->postJson("/api/public-apply/{$link->token}/estimate", [], $this->applicantSession($session)),
        fn () => $this->post("/api/public-apply/{$link->token}/documents", ['type' => 'government_id', 'file' => $this->png()], [...$this->applicantSession($session), 'Accept' => 'application/json']),
    ] as $call) {
        $call()->assertStatus(409)->assertJsonPath('message', PublicPortal::LINK_OFF);
    }

    $link->forceFill(['active' => true])->save();
    $this->travel(61)->seconds();
    $code = $this->requestCode($link, null, 'resume')->assertOk()->json('data.test_mode.code');
    $link->forceFill(['active' => false])->save();

    $this->verifyCode($link, $code, null, 'resume')->assertStatus(409)->assertJsonPath('message', PublicPortal::LINK_OFF);
});

it('limits code checks to 10 in 15 minutes per mobile', function () {
    $link = $this->enableIntake();

    foreach (range(1, 10) as $i) {
        $this->verifyCode($link, '000000')->assertUnprocessable();
    }

    $this->verifyCode($link, '000000')->assertStatus(429);
    $this->verifyCode($link, '000000', $this->secondStaffMobile)->assertUnprocessable();
});

it('limits code requests to 10 an hour per IP, whatever the mobile', function () {
    $link = $this->enableIntake();

    foreach (range(1, 10) as $i) {
        $this->requestCode($link, sprintf('0918%07d', $i))->assertOk();
    }

    $this->requestCode($link, '09180000099')->assertStatus(429);
});

it('limits each session-keyed route per IP too, so a new random session each time is still metered', function (string $method, string $path, int $limit) {
    $link = $this->enableIntake();
    $url = "/api/public-apply/{$link->token}/{$path}";

    foreach (range(1, $limit) as $i) {
        $this->json($method, $url, [], $this->applicantSession(Str::random(64)))->assertUnauthorized();
    }

    $this->json($method, $url, [], $this->applicantSession(Str::random(64)))->assertStatus(429);
})->with([
    'drafts' => ['PUT', 'draft', 120],
    'estimate' => ['POST', 'estimate', 60],
    'submit' => ['POST', 'submit', 20],
    'logout' => ['POST', 'logout', 30],
]);

it('answers 404, never 429, while the server switch is off', function () {
    $link = $this->enableIntake();
    config(['loan_origination.enabled' => false]);

    foreach (range(1, 70) as $i) {
        $response = $this->getJson("/api/public-apply/{$link->token}")->assertNotFound();
    }

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('counts toward the daily cap only codes that reached someone or were shown in test mode', function () {
    $link = $this->enableIntake();
    config(['loan_origination_intake.otp.daily_cap' => 2]);
    $this->withoutMiddleware(ThrottleRequests::class);

    foreach (range(1, 5) as $i) {
        $this->requestCode($link, sprintf('0918%07d', $i))->assertOk()->assertJsonMissingPath('data.test_mode');
    }

    $this->requestCode($link)->assertOk()->assertJsonPath('data.test_mode.notice', 'Test mode: not sent');
    $this->requestCode($link, $this->secondStaffMobile)->assertOk();
    $this->travel(61)->seconds();
    $this->requestCode($link)->assertStatus(429)->assertJsonPath('code', 'daily_cap');
});

it('caps the codes one mobile can ask for in a day', function () {
    $link = $this->enableIntake();
    config(['loan_origination_intake.otp.daily_cap_per_mobile' => 3]);
    $this->withoutMiddleware(ThrottleRequests::class);

    foreach (range(1, 3) as $i) {
        $this->requestCode($link, '09181112222')->assertOk();
    }

    $this->requestCode($link, '09181112222')->assertStatus(429)->assertJsonPath('code', 'mobile_daily_cap');
    $this->requestCode($link)->assertOk();
});

it('shows test codes only on links marked as a test', function () {
    $link = $this->enableIntake(['is_test' => false]);

    $this->getJson("/api/public-apply/{$link->token}")->assertJsonPath('data.otp_channels', [])
        ->assertJsonPath('data.closed_reason', 'Online applications open once text messages can be sent.');
    $this->requestCode($link)->assertStatus(409);

    $test = $this->enableIntake(['name' => 'Staff test link']);
    $this->travel(61)->seconds();
    $this->requestCode($test)->assertOk()->assertJsonPath('data.test_mode.notice', 'Test mode: not sent');
});

it('closes every link while the server can\'t tell visitors apart, and logs it once', function () {
    $link = $this->enableIntake();
    config(['trustedproxy.proxy_secret' => '']);
    Log::spy();

    $page = $this->getJson("/api/public-apply/{$link->token}")->assertJsonPath('data.accepting', false)
        ->assertJsonPath('data.closed_reason', PublicPortal::NOT_READY);
    $this->getJson("/api/public-apply/{$link->token}")->assertJsonPath('data.accepting', false);
    $this->requestCode($link)->assertStatus(409)->assertJsonPath('message', PublicPortal::NOT_READY);

    expect($page->getContent())->not->toContain('limit')->not->toContain('tell visitors');
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'LENDYPH_PROXY_SECRET'));
    expect(OriginationApplication::query()->count())->toBe(0);
});

it('keeps the settings switch first among the reasons', function () {
    $link = $this->enableIntake();
    config(['trustedproxy.proxy_secret' => '']);
    OriginationSetting::current()->forceFill(['online_applications_enabled' => false])->save();

    $this->getJson("/api/public-apply/{$link->token}")->assertJsonPath('data.closed_reason', PublicPortal::SETTINGS_OFF);
});
