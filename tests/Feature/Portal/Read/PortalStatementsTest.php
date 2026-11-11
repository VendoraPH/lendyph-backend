<?php

namespace Tests\Feature\Portal\Read;

use App\Models\AuditLog;
use App\Models\ShareCapitalPledge;
use App\Services\Portal\Pdf\PortalPdf;
use App\Services\Portal\Pdf\PortalStatements;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The server-made PDFs a member downloads (portal design §1, §3): each is
 * built from the staff statement builder, renders ₱ in DejaVu Sans, is
 * written for the signed-in member only, and leaves an audit row with the
 * member as the actor.
 */
class PortalStatementsTest extends TestCase
{
    use PortalReadFixtures, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00', 'Asia/Manila'));
        $this->enablePortal();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_statement_is_a_pdf_in_dejavu_sans_and_is_audited(): void
    {
        $me = $this->portalMember();
        $loan = $this->releasedLoanFor($me);
        $this->pay($loan, 5000);
        $account = $this->savingsFor($me, 250_000);
        $deposit = $this->timeDepositFor($me);
        $this->shareCapital($me, '2026-01-10', '1500.00');
        $token = $this->portalToken($me);

        $uris = [
            "/api/portal/loans/{$loan->id}/statement",
            '/api/portal/share-capital/statement?from=2026-01-01&to=2026-11-10',
            '/api/portal/share-capital/certificate?as_of=2026-11-10',
            "/api/portal/savings/{$account->id}/statement?from=2026-10-01&to=2026-11-10",
            "/api/portal/time-deposits/{$deposit->id}/certificate",
        ];

        foreach ($uris as $uri) {
            $before = AuditLog::query()->where('action', 'portal_statement_downloaded')->count();
            $response = $this->portalJson('GET', $uri, [], $token);

            $this->assertPdf($response, $uri);
            $this->assertSame($before + 1, AuditLog::query()->where('action', 'portal_statement_downloaded')->count(), $uri);
            $row = AuditLog::query()->where('action', 'portal_statement_downloaded')->latest('id')->firstOrFail();
            $this->assertSame('member', $row->actor_type, $uri);
            $this->assertSame($me->id, (int) $row->portal_user_id, $uri);
        }
    }

    public function test_the_statement_html_prints_peso_figures_from_the_staff_builders(): void
    {
        $me = $this->portalMember();
        $loan = $this->releasedLoanFor($me);
        ShareCapitalPledge::query()->updateOrCreate(['borrower_id' => $me->borrower_id], ['amount' => '5000.00']);
        $this->shareCapital($me, '2026-01-10', '1500.00');

        $soa = $this->staffGet("/api/reports/statement-of-account/{$loan->id}")->json('data');
        $pdf = app(PortalPdf::class);
        $html = $pdf->html(app(PortalStatements::class)->loan($loan));

        $this->assertStringContainsString('DejaVu Sans', $html);
        $this->assertStringContainsString('₱'.number_format($soa['summary']['outstanding_balance'], 2), $html);
        $this->assertStringContainsString('As of', $html);
        $this->assertStringNotContainsString('$', strip_tags(preg_replace('/<style.*?<\/style>/s', '', $html)));

        $certificate = $pdf->html(app(PortalStatements::class)->shareCapitalCertificate($me->borrower, '2026-11-10'));
        $this->assertStringContainsString('₱1,500.00', $certificate);
    }

    public function test_statement_ranges_are_checked_after_the_record(): void
    {
        $me = $this->portalMember();
        $account = $this->savingsFor($me, 100_000);
        $token = $this->portalToken($me);

        $this->portalJson('GET', '/api/portal/share-capital/statement?from=2020-01-01&to=2026-11-10', [], $token)->assertUnprocessable();
        $this->portalJson('GET', '/api/portal/share-capital/statement?from=2026-01-01&to=2026-11-11', [], $token)->assertUnprocessable();
        $this->portalJson('GET', '/api/portal/share-capital/statement?from=2026-05-01&to=2026-04-01', [], $token)->assertUnprocessable();
        $this->portalJson('GET', '/api/portal/share-capital/statement', [], $token)->assertUnprocessable();
        $this->portalJson('GET', '/api/portal/share-capital/certificate?as_of=2026-11-11', [], $token)->assertUnprocessable();
        $this->portalJson('GET', "/api/portal/savings/{$account->id}/statement?from=2026-11-05&to=2026-11-01", [], $token)->assertUnprocessable();
    }

    public function test_statements_switched_off_answer_403(): void
    {
        $this->enablePortal(['features' => ['statements' => false]]);
        $me = $this->portalMember();
        $loan = $this->releasedLoanFor($me);

        $this->portalJson('GET', "/api/portal/loans/{$loan->id}/statement", [], $this->portalToken($me))->assertForbidden();
    }

    private function assertPdf(TestResponse $response, string $uri): void
    {
        $this->assertSame(200, $response->status(), $uri.': '.substr((string) $response->getContent(), 0, 400));
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'), $uri);
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'), $uri);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), $uri);

        $bytes = (string) $response->getContent();
        $this->assertStringStartsWith('%PDF-', $bytes, $uri);
        $this->assertStringContainsString('DejaVuSans', $bytes, $uri.': DejaVu Sans is embedded, so ₱ renders.');
    }
}
