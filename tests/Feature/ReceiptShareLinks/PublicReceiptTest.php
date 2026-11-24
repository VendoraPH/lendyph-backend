<?php

namespace Tests\Feature\ReceiptShareLinks;

use App\Http\Middleware\ReceiptShare\EnsureReceiptShareLinksEnabled;
use App\Http\Middleware\ReceiptShare\PublicReceiptHeaders;
use App\Http\Middleware\TrustFrontendProxy;
use App\Models\AuditLog;
use App\Models\BrandingSetting;
use App\Models\Loan;
use App\Models\ReceiptShareLink;
use App\Models\Repayment;
use App\Providers\ReceiptShareServiceProvider;
use App\Services\ReceiptShareLinks\ReceiptShareLinks;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * A shared receipt, public side (finish run round 2, decision 6): no sign-in,
 * masked (initials and surname; no contacts, account numbers, balances, next
 * due date or other loans), VOIDED with its date, the same 404 for every
 * refusal, its own limiter, no-store, and each view audited as the system with
 * a hash of the address.
 */
class PublicReceiptTest extends TestCase
{
    use SetupLendyPH;

    private const ADDRESS = '203.0.113.7';

    private Loan $loan;

    private Repayment $repayment;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        config(['receipt_share_links.enabled' => true, 'trustedproxy.proxy_secret' => str_repeat('s', 40)]);
        BrandingSetting::current()->update(['organization_name' => 'Binhs Multi-Purpose Cooperative']);

        $this->loan = $this->createReleasedLoan();
        $this->loan->borrower->forceFill([
            'first_name' => 'Maria Clara',
            'middle_name' => 'dela Cruz',
            'last_name' => 'Santos',
            'suffix' => null,
            'contact_number' => '09171234567',
            'email' => 'maria.santos@example.com',
            'street_address' => '12 Sampaguita Street',
        ])->save();

        $this->repayment = Repayment::factory()->create([
            'loan_id' => $this->loan->id,
            'payment_date' => '2026-11-20',
            'method' => 'gcash',
            'reference_number' => 'GC-1234567890',
            'amount_paid' => 12000,
            'principal_applied' => 10000,
            'interest_applied' => 1800,
            'penalty_applied' => 150.50,
            'insurance_applied' => 49.50,
            'overpayment' => 0,
            'balance_before' => 60000,
            'balance_after' => 50000,
            'remarks' => 'Paid at the Main Branch by Maria Santos',
            'received_by' => $this->admin->id,
        ]);

        [, $this->token] = app(ReceiptShareLinks::class)->create($this->repayment, $this->admin);

        // Everything below is a visitor with no account.
        $this->app['auth']->forgetGuards();
    }

    private function openShared(?string $token = null, string $address = self::ADDRESS, array $headers = []): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $address])
            ->getJson('/api/public/receipts/'.($token ?? $this->token), $headers);
    }

    public function test_a_working_link_shows_only_the_masked_receipt(): void
    {
        $response = $this->openShared()->assertOk();

        $this->assertSame([
            'cooperative' => ['name' => 'Binhs Multi-Purpose Cooperative', 'logo_url' => null],
            'receipt_number' => $this->repayment->receipt_number,
            'payment_date' => '2026-11-20',
            'member_name' => 'M. C. D. Santos',
            'method' => 'GCash',
            'reference_last4' => '7890',
            'amount_paid' => '12000.00',
            'principal_applied' => '10000.00',
            'interest_applied' => '1800.00',
            'penalty_applied' => '150.50',
            'insurance_applied' => '49.50',
            'overpayment' => '0.00',
            'build_up_credited' => '0.00',
            'overpayment_kept' => '0.00',
            'status' => 'completed',
            'voided_on' => null,
        ], $response->json('data'));

        $body = $response->getContent();
        $member = $this->loan->borrower;
        foreach ([
            'first name' => 'Maria', 'middle name' => 'dela Cruz', 'member code' => $member->borrower_code,
            'mobile' => '09171234567', 'email' => 'maria.santos@example.com', 'address' => 'Sampaguita',
            'account number' => $this->loan->loan_account_number, 'product' => $this->loan->loanProduct->name,
            'whole reference' => 'GC-1234567890', 'remarks' => 'Paid at the Main Branch',
            'collector' => $this->admin->full_name,
        ] as $what => $secret) {
            $this->assertStringNotContainsString((string) $secret, $body, "the {$what} leaked");
        }

        foreach (['balance', 'next_due', 'loan', 'borrower', 'contact', 'email', 'collected_by', 'received_by', 'remarks', 'void_reason', 'token'] as $key) {
            $this->assertStringNotContainsString("\"{$key}", $body, "a {$key} field is sent");
        }
    }

    public function test_the_logo_path_is_sent_only_while_a_logo_is_stored(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/logo.png', 'png');
        BrandingSetting::current()->update(['logo_path' => 'branding/logo.png']);

        $this->openShared()->assertOk()->assertJsonPath('data.cooperative.logo_url', '/branding/logo');

        Storage::disk('public')->delete('branding/logo.png');
        $this->openShared()->assertOk()->assertJsonPath('data.cooperative.logo_url', null);
    }

    public function test_a_short_reference_is_never_shown_and_a_cash_payment_reads_in_words(): void
    {
        $this->repayment->forceFill(['method' => 'cash', 'reference_number' => '1234'])->save();
        $this->openShared()->assertOk()->assertJsonPath('data.method', 'Cash')->assertJsonPath('data.reference_last4', null);

        $this->repayment->forceFill(['method' => 'bank_transfer', 'reference_number' => null])->save();
        $this->openShared()->assertOk()->assertJsonPath('data.method', 'Bank Transfer')->assertJsonPath('data.reference_last4', null);
    }

    public function test_a_voided_receipt_reads_voided_with_its_void_date(): void
    {
        $this->repayment->forceFill([
            'status' => 'voided',
            'voided_at' => '2026-11-21 16:45:00',
            'voided_by' => $this->admin->id,
            'void_reason' => 'Duplicate entry by the teller',
        ])->save();

        $response = $this->openShared()->assertOk()
            ->assertJsonPath('data.status', 'voided')
            ->assertJsonPath('data.voided_on', '2026-11-21');

        $this->assertStringNotContainsString('Duplicate entry', $response->getContent());
    }

    public function test_unknown_expired_revoked_malformed_and_switched_off_links_get_the_same_404(): void
    {
        $answers = [];
        $answers['unknown'] = $this->openShared(strtr(base64_encode(random_bytes(32)), ['+' => '-', '/' => '_', '=' => '']));
        $answers['malformed'] = $this->openShared('not-a-token');
        $answers['case changed'] = $this->openShared(strtoupper($this->token) === $this->token ? strtolower($this->token) : strtoupper($this->token));

        config(['receipt_share_links.enabled' => false]);
        $answers['switched off'] = $this->openShared();
        config(['receipt_share_links.enabled' => true]);

        $this->travel(30)->days();
        $this->travel(1)->seconds();
        $answers['expired'] = $this->openShared();
        $this->travelBack();

        [, $revokedToken] = $this->freshLinkFor(Repayment::factory()->create(['loan_id' => $this->loan->id, 'received_by' => $this->admin->id]));
        app(ReceiptShareLinks::class)->revoke(Repayment::query()->latest('id')->first(), $this->admin);
        $answers['revoked'] = $this->openShared($revokedToken);

        foreach ($answers as $case => $response) {
            $this->assertSame(404, $response->status(), $case);
            $this->assertSame('{"message":"Not found."}', $response->getContent(), $case);
            $this->assertSame('application/json', $response->headers->get('Content-Type'), $case);
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), $case);
        }

        $this->assertSame(0, AuditLog::query()->where('action', 'receipt_share_link_opened')->count());
    }

    public function test_each_view_is_audited_as_the_system_with_a_hash_of_the_address(): void
    {
        $this->openShared()->assertOk();
        $this->openShared()->assertOk();
        $this->openShared(address: '198.51.100.23')->assertOk();

        $rows = AuditLog::query()->where('action', 'receipt_share_link_opened')->orderBy('id')->get();
        $this->assertCount(3, $rows);

        foreach ($rows as $row) {
            $this->assertNull($row->user_id);
            $this->assertNull($row->actor_type);
            $this->assertNull($row->portal_user_id);
            $this->assertSame(Repayment::class, $row->auditable_type);
            $this->assertSame((int) $this->repayment->id, (int) $row->auditable_id);
            $this->assertSame((int) $this->loan->branch_id, (int) $row->branch_id);
            $this->assertMatchesRegularExpression('/^hash:[0-9a-f]{32}$/', $row->ip_address);
            $this->assertStringNotContainsString(self::ADDRESS, json_encode($row->getAttributes()));
            $this->assertStringNotContainsString('198.51.100.23', json_encode($row->getAttributes()));
            $this->assertStringNotContainsString($this->token, json_encode($row->getAttributes()));
        }

        $this->assertSame($rows[0]->ip_address, $rows[1]->ip_address);
        $this->assertNotSame($rows[0]->ip_address, $rows[2]->ip_address);
        $this->assertSame(ReceiptShareLinks::addressHash(self::ADDRESS), $rows[0]->ip_address);
    }

    public function test_a_staff_token_sent_along_changes_nothing(): void
    {
        $staffToken = $this->admin->createToken('test')->plainTextToken;

        $asGuest = $this->openShared()->assertOk()->json('data');
        $withToken = $this->openShared(headers: ['Authorization' => "Bearer {$staffToken}"])->assertOk()->json('data');

        $this->assertSame($asGuest, $withToken);
        $this->assertSame(0, AuditLog::query()->where('action', 'receipt_share_link_opened')->whereNotNull('user_id')->count());
    }

    public function test_answers_are_no_store_and_never_indexed(): void
    {
        foreach ([$this->openShared()->assertOk(), $this->openShared('x')->assertNotFound()] as $response) {
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        }
    }

    public function test_views_are_limited_per_address_and_the_limit_answers_in_words(): void
    {
        $this->freezeSecond();
        for ($i = 0; $i < ReceiptShareServiceProvider::VIEWS_PER_MINUTE; $i++) {
            $this->openShared()->assertOk();
        }

        $limited = $this->openShared()->assertStatus(429);
        $this->assertSame('{"message":"This receipt was opened too many times. Please try again later."}', $limited->getContent());
        $this->assertStringContainsString('no-store', (string) $limited->headers->get('Cache-Control'));
        // The address's own window: a minute from its first view.
        $this->assertSame('60', $limited->headers->get('Retry-After'));

        $this->openShared(address: '198.51.100.23')->assertOk();
    }

    public function test_one_link_opens_at_most_60_times_an_hour_however_many_addresses_ask(): void
    {
        $this->freezeSecond();
        // Rotating IPv6 addresses each stay far under the per-address limit.
        for ($i = 1; $i <= 60; $i++) {
            $this->openShared(address: '2001:db8::'.dechex($i))->assertOk();
        }

        $this->travel(10)->minutes();
        $capped = $this->openShared(address: '2001:db8::ffff')->assertStatus(429);
        // The same words as the address limit, promising no time.
        $this->assertSame('{"message":"This receipt was opened too many times. Please try again later."}', $capped->getContent());
        $this->assertStringContainsString('no-store', (string) $capped->headers->get('Cache-Control'));
        // The link's own window: an hour from its first view, 50 minutes left.
        $this->assertSame('3000', $capped->headers->get('Retry-After'));

        // Every allowed view is audited; the refused one isn't.
        $this->assertSame(60, AuditLog::query()->where('action', 'receipt_share_link_opened')->count());

        // The cap is the link's own: another receipt's link still opens.
        [, $other] = $this->freshLinkFor(Repayment::factory()->create(['loan_id' => $this->loan->id, 'received_by' => $this->admin->id]));
        $this->openShared($other, address: '2001:db8::ffff')->assertOk();

        $this->travel(51)->minutes();
        $this->openShared(address: '2001:db8::ffff')->assertOk();
    }

    public function test_links_stay_off_while_the_proxy_secret_is_unset_and_it_is_logged_once(): void
    {
        Log::spy();
        $staffStatus = "/api/repayments/{$this->repayment->id}/share-link";

        foreach (['', str_repeat('s', TrustFrontendProxy::MIN_SECRET_LENGTH - 1)] as $secret) {
            config(['trustedproxy.proxy_secret' => $secret]);

            $this->openShared()->assertNotFound()->assertExactJson(['message' => 'Not found.']);
            $this->actingAs($this->admin)->getJson($staffStatus)->assertNotFound();
            $this->actingAs($this->admin)->postJson($staffStatus)->assertNotFound();
            $this->app['auth']->forgetGuards();
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'LENDYPH_PROXY_SECRET'));
        $this->assertSame(0, AuditLog::query()->where('action', 'receipt_share_link_opened')->count());
        $this->assertSame(1, ReceiptShareLink::query()->count());

        config(['trustedproxy.proxy_secret' => str_repeat('s', TrustFrontendProxy::MIN_SECRET_LENGTH)]);
        $this->openShared()->assertOk();
    }

    public function test_while_the_switch_is_off_it_answers_404_and_never_429(): void
    {
        config(['receipt_share_links.enabled' => false]);

        for ($i = 0; $i <= ReceiptShareServiceProvider::VIEWS_PER_MINUTE + 5; $i++) {
            $this->openShared()->assertNotFound();
        }

        $this->assertSame(0, AuditLog::query()->where('action', 'receipt_share_link_opened')->count());
    }

    public function test_the_route_sits_outside_the_staff_group_with_the_switch_and_headers_ahead_of_its_own_limiter(): void
    {
        $route = Route::getRoutes()->getByName('public-receipts.show');
        $this->assertNotNull($route);
        $this->assertSame('api/public/receipts/{token}', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());

        $declared = $route->gatherMiddleware();
        $this->assertNotContains('auth:sanctum', $declared);
        $this->assertContains('throttle:receipt-share-view', $declared);
        $this->assertNotNull(RateLimiter::limiter('receipt-share-view'));

        $stack = array_values(array_filter(app('router')->gatherRouteMiddleware($route), 'is_string'));
        $at = fn (callable $match): int => (int) array_key_first(array_filter($stack, $match));
        $headers = $at(fn (string $m) => $m === PublicReceiptHeaders::class);
        $switch = $at(fn (string $m) => $m === EnsureReceiptShareLinksEnabled::class);
        $firstThrottle = $at(fn (string $m) => str_starts_with($m, ThrottleRequests::class));

        $this->assertLessThan($switch, $headers, 'the headers wrap the switch');
        $this->assertLessThan($firstThrottle, $switch, 'the switch runs ahead of every limiter');
    }

    public function test_the_shared_api_limiter_exempts_it_only_by_both_its_name_and_its_path(): void
    {
        $limiter = RateLimiter::limiter('api');
        $requestFor = function (string $name, string $uri): Request {
            $request = Request::create('/'.$uri, 'GET');
            $route = new RoutingRoute(['GET'], $uri, []);
            $route->name($name);
            $request->setRouteResolver(fn () => $route);
            $request->setUserResolver(fn () => null);

            return $request;
        };

        $this->assertInstanceOf(Unlimited::class, $limiter($requestFor('public-receipts.show', 'api/public/receipts/abc')));
        $this->assertNotInstanceOf(Unlimited::class, $limiter($requestFor('public-receipts.probe', 'api/loans/7')));
        $this->assertNotInstanceOf(Unlimited::class, $limiter($requestFor('loans.show', 'api/public/receipts/abc')));
    }

    public function test_its_provider_is_registered(): void
    {
        $this->assertContains(ReceiptShareServiceProvider::class, require base_path('bootstrap/providers.php'));
    }

    /** @return array{0: ReceiptShareLink, 1: string} */
    private function freshLinkFor(Repayment $repayment): array
    {
        return app(ReceiptShareLinks::class)->create($repayment, $this->admin);
    }
}
