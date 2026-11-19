<?php

namespace Tests\Feature\Phase6\Amla\Reports;

use App\Models\AmlaExport;
use App\Models\AmlaLogEntry;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * AMLA report exports (AMLA-B7): PDF, XLSX and CSV made by the server, ₱ with
 * two decimals and negative money in parentheses, DRAFT while the period
 * isn't over, downloaded through a signed link that is re-checked against
 * the user it was made for (same user, still current, still holding the
 * report's keys), and every export and download in the AMLA log.
 */
class AmlaReportExportTest extends TestCase
{
    use ReportTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startCases();
        Storage::fake('private');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function export(string $key, string $format, array $query = []): array
    {
        return $this->postJson("/api/amla/compliance-reports/{$key}/exports", ['format' => $format, ...$query])->assertCreated()->json('data');
    }

    public function test_each_format_is_made_by_the_server_logged_and_downloaded_by_its_owner(): void
    {
        $member = $this->member();
        $this->event($member, '1000.00');
        $this->event($member, '2500.50', ['direction' => 'out', 'kind' => 'savings_withdrawal']);
        $officer = $this->amlaUser(['amla:alerts', 'amla:export']);
        $this->actingAs($officer);
        $outside = $this->outsideAmlaCounts();

        $csv = $this->export('transaction_monitoring', 'csv', ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $body = $this->get($csv['download_url'])->assertOk()->streamedContent();
        $this->assertStringContainsString('DRAFT', $body);
        $this->assertStringContainsString('"(₱1,500.50)"', $body);
        $this->assertStringContainsString('₱1,000.00', $body);

        $pdf = $this->export('transaction_monitoring', 'pdf', ['from' => '2026-11-01', 'to' => '2026-11-30']);
        $this->assertStringStartsWith('%PDF', $this->get($pdf['download_url'])->assertOk()->streamedContent());

        $xlsx = $this->export('transaction_monitoring', 'xlsx', ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $this->assertStringStartsWith('PK', $this->get($xlsx['download_url'])->assertOk()->streamedContent());

        $this->assertSame(3, AmlaLogEntry::query()->where('action', 'amla_report_exported')->where('user_id', $officer->id)->count());
        $this->assertSame(3, AmlaLogEntry::query()->where('action', 'amla_report_export_downloaded')->count());
        $this->assertSame(3, AmlaExport::query()->count());
        $this->assertTrue(AmlaExport::query()->findOrFail($xlsx['id'])->is_draft === false);
        $this->assertNothingWrittenOutsideAmla($outside);
    }

    public function test_the_signed_link_is_rechecked_against_the_user_it_was_made_for(): void
    {
        $owner = $this->amlaUser(['amla:alerts', 'amla:export']);
        $other = $this->amlaUser(['amla:alerts', 'amla:export']);
        $url = $this->actingAs($owner)->export('transaction_monitoring', 'csv')['download_url'];

        $this->actingAs($other)->get($url, ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($owner)->get($url.'x', ['Accept' => 'application/json'])->assertForbidden();

        $owner->roles()->first()->revokePermissionTo('amla:alerts');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($owner->fresh())->get($url, ['Accept' => 'application/json'])->assertForbidden();

        $second = $this->amlaUser(['amla:alerts', 'amla:export']);
        $again = $this->actingAs($second)->export('transaction_monitoring', 'csv')['download_url'];
        User::query()->whereKey($second->id)->update(['file_link_version' => 7]);
        $this->actingAs($second->fresh())->get($again, ['Accept' => 'application/json'])->assertForbidden();

        $this->assertGreaterThanOrEqual(3, AmlaLogEntry::query()->where('action', 'access_denied')->count());
    }

    public function test_an_export_holds_only_the_callers_branches_and_a_confidential_one_needs_head_office(): void
    {
        $other = Branch::factory()->create();
        $mine = $this->ctrCandidate();
        $theirs = $this->ctrCandidate($this->member($other->id));
        $url = $this->actingAs($this->amlaUser(['amla:ctr', 'amla:export']))->export('covered_transactions', 'csv', ['from' => '2026-11-01', 'to' => '2026-11-30'])['download_url'];

        $body = $this->get($url)->assertOk()->streamedContent();
        $this->assertStringContainsString($mine->reference, $body);
        $this->assertStringNotContainsString($theirs->reference, $body);

        $this->actingAs($this->amlaUser(['amla:cases', 'amla:export']))->postJson('/api/amla/compliance-reports/case_register/exports', ['format' => 'csv'])->assertForbidden();
        $this->actingAs($this->amlaUser(['amla:ctr', 'amla:export']))->postJson('/api/amla/compliance-reports/covered_transactions/exports', ['format' => 'docx'])->assertUnprocessable();
    }
}
