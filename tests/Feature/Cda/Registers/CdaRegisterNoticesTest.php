<?php

namespace Tests\Feature\Cda\Registers;

use App\Models\CdaDocument;
use App\Models\CdaNoticeSent;
use App\Models\CdaOfficer;
use App\Models\CdaOfficerTraining;
use App\Models\CdaSetting;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use App\Services\Cda\Registers\RegisterNotices;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The nightly register notices (CDA design §10): a document expiring within
 * 30 days and an officer missing a mandatory training, each sent once
 * (`cda_notices_sent`), in-app only, to active users holding `cda:prepare`
 * or `cda:approve` with `branches:view_all`, and the restricted view
 * permission for an audit, tax or mediation document.
 */
class CdaRegisterNoticesTest extends TestCase
{
    use RegisterHelpers, SetupLendyPH;

    private User $preparer;

    private User $approverWithAudit;

    private User $noViewAll;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        $this->freezeClock();
        DB::table('notifications')->delete();
        User::query()->update(['status' => 'inactive']);
        $this->preparer = $this->cdaUser([CdaAccess::VIEW, CdaAccess::PREPARE]);
        $this->approverWithAudit = $this->cdaUser([CdaAccess::VIEW, CdaAccess::APPROVE, CdaAccess::VIEW_AUDIT]);
        $this->noViewAll = $this->userWith([CdaAccess::VIEW, CdaAccess::PREPARE]);
        $this->cdaUser([CdaAccess::VIEW]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<int, list<string>> user id => notification types */
    private function received(): array
    {
        return DB::table('notifications')->orderBy('id')->get()
            ->groupBy('notifiable_id')
            ->map(fn ($rows) => $rows->pluck('type')->all())
            ->all();
    }

    public function test_an_expiring_document_is_noticed_once_to_the_right_people(): void
    {
        $period = $this->period();
        $soon = CdaDocument::factory()->create(['period_id' => $period->id, 'category' => 'minutes', 'title' => 'Board minutes', 'expires_on' => '2026-12-01']);
        $audit = CdaDocument::factory()->create(['period_id' => $period->id, 'category' => 'audit_report', 'title' => 'Audited FS', 'expires_on' => '2026-11-20']);
        CdaDocument::factory()->create(['period_id' => $period->id, 'category' => 'minutes', 'expires_on' => '2027-01-15']);
        CdaDocument::factory()->create(['period_id' => $period->id, 'category' => 'minutes', 'expires_on' => '2026-11-20', 'status' => 'withdrawn']);

        $this->artisan('cda:register-notices')->assertSuccessful();
        $this->artisan('cda:register-notices')->assertSuccessful();

        $received = $this->received();
        $this->assertSame([RegisterNotices::DOCUMENT_EXPIRING], $received[$this->preparer->id]);
        $this->assertSame([RegisterNotices::DOCUMENT_EXPIRING, RegisterNotices::DOCUMENT_EXPIRING], $received[$this->approverWithAudit->id]);
        $this->assertArrayNotHasKey($this->noViewAll->id, $received);
        $this->assertCount(2, $received);

        $body = json_decode((string) DB::table('notifications')->where('notifiable_id', $this->preparer->id)->value('data'), true);
        $this->assertSame('Board minutes expires on Dec 1, 2026 (in 20 days).', $body['body']);
        $this->assertSame(2, CdaNoticeSent::query()->where('kind', RegisterNotices::DOCUMENT_EXPIRING)->count());

        $soon->update(['expires_on' => '2026-12-05']);
        $this->artisan('cda:register-notices')->assertSuccessful();
        $this->assertCount(2, $this->received()[$this->preparer->id]);
        $this->assertSame($audit->id, CdaNoticeSent::query()->where('subject_id', $audit->id)->value('subject_id'));
    }

    public function test_an_officer_missing_a_mandatory_training_is_noticed_once_a_year(): void
    {
        CdaSetting::current()->update(['required_trainings' => ['treasurer' => ['fundamentals', 'basic_accounting'], 'secretary' => ['fundamentals']]]);
        $treasurer = CdaOfficer::factory()->create(['name' => 'Ana Reyes', 'position' => 'treasurer', 'term_start' => '2025-01-01']);
        $secretary = CdaOfficer::factory()->create(['name' => 'Done Already', 'position' => 'secretary', 'term_start' => '2025-01-01']);
        CdaOfficer::factory()->create(['name' => 'Past Treasurer', 'position' => 'treasurer', 'term_start' => '2020-01-01', 'term_end' => '2024-12-31']);
        CdaOfficerTraining::factory()->create(['officer_id' => $treasurer->id, 'requirement_key' => 'fundamentals', 'completed_on' => '2025-02-01']);
        CdaOfficerTraining::factory()->create(['officer_id' => $secretary->id, 'requirement_key' => 'fundamentals', 'completed_on' => '2025-02-01']);

        $this->artisan('cda:register-notices')->assertSuccessful();
        $this->artisan('cda:register-notices')->assertSuccessful();

        $this->assertSame([RegisterNotices::TRAINING_INCOMPLETE], $this->received()[$this->preparer->id]);
        $body = json_decode((string) DB::table('notifications')->where('notifiable_id', $this->preparer->id)->value('data'), true);
        $this->assertSame('Ana Reyes (Treasurer) has not completed the required training: Basic Accounting.', $body['body']);
        $this->assertSame('/cda/officers', $body['url']);
        $this->assertSame('2026', CdaNoticeSent::query()->where('subject_id', $treasurer->id)->value('dedupe_key'));
    }

    public function test_a_notice_with_no_recipients_is_kept_until_someone_can_receive_it(): void
    {
        User::query()->update(['status' => 'inactive']);
        $document = CdaDocument::factory()->create(['period_id' => $this->period()->id, 'category' => 'tax', 'title' => 'Tax exemption', 'expires_on' => '2026-11-25']);

        $this->artisan('cda:register-notices')->assertSuccessful();
        $this->assertSame(0, CdaNoticeSent::query()->count());
        $this->assertSame(0, DB::table('notifications')->count());

        $taxPreparer = $this->cdaUser([CdaAccess::VIEW, CdaAccess::PREPARE, CdaAccess::VIEW_TAX]);
        $this->artisan('cda:register-notices')->assertSuccessful();
        $this->artisan('cda:register-notices')->assertSuccessful();

        $this->assertSame([RegisterNotices::DOCUMENT_EXPIRING], $this->received()[$taxPreparer->id]);
        $this->assertSame(1, CdaNoticeSent::query()->where('subject_id', $document->id)->count());
    }
}
