<?php

namespace Tests\Feature\Cda\Reports;

use App\Models\AuditLog;
use App\Models\CdaReport;
use App\Models\User;
use App\Services\Cda\CdaAccess;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * Phase 3 follow-ups, item B2. The workflow audit text ends with its own full
 * stop, so a reason typed with one ("…future.") read "…future..". The sentence
 * now ends once: a reason's trailing full stops are dropped, and a reason that
 * ends with "?" or "!" keeps it and gets no full stop after it. A reason needs
 * a letter or digit ("." alone is a 422). The stored reason stays as typed.
 */
class CdaReasonSentenceTest extends TestCase
{
    use CdaReportTestHelpers, SetupLendyPH;

    private CdaReport $report;

    private User $preparer;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
        Carbon::setTestNow(Carbon::parse('2027-01-15 10:00:00', 'Asia/Manila'));
        $this->brandAs('Binhi Multi-Purpose Cooperative');
        $this->member(['gender' => 'male']);

        $this->report = $this->caprWithTestTemplate();
        $this->preparer = $this->cdaStaff();
        $this->approver = $this->userWith(['branches:view_all', CdaAccess::VIEW, CdaAccess::APPROVE, CdaAccess::REVIEW]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function returnWith(string $reason): AuditLog
    {
        $this->actingAs($this->preparer)->putJson("/api/cda/reports/{$this->report->id}", ['fields' => [['field_key' => 'employment.employees_regular', 'value' => 2]]])->assertOk();
        $this->actingAs($this->preparer)->postJson("/api/cda/reports/{$this->report->id}/workflow", ['action' => 'submit_for_review'])->assertOk();
        $this->actingAs($this->approver)->postJson("/api/cda/reports/{$this->report->id}/workflow", ['action' => 'return', 'reason' => $reason])->assertOk();

        return AuditLog::query()->where('action', 'cda_workflow')->where('auditable_id', $this->report->id)->latest('id')->firstOrFail();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function reasons(): array
    {
        return [
            'a full stop' => ['Recount the employees for the future.', 'returned: Recount the employees for the future.'],
            'several full stops' => ['Recount the employees...', 'returned: Recount the employees.'],
            'none' => ['Recount the employees', 'returned: Recount the employees.'],
            'an exclamation mark, kept' => ['Recount the employees!', 'returned: Recount the employees!'],
            'a question mark, kept' => ['Did we count the employees?', 'returned: Did we count the employees?'],
            'a question mark and a full stop' => ['Did we count the employees?.', 'returned: Did we count the employees?'],
        ];
    }

    #[DataProvider('reasons')]
    public function test_a_return_reason_ends_the_sentence_once(string $typed, string $ending): void
    {
        $row = $this->returnWith($typed);

        $this->assertStringEndsWith($ending, $row->description);
        $this->assertStringNotContainsString('..', $row->description);
        $this->assertStringNotContainsString('?.', $row->description);
        $this->assertStringNotContainsString('!.', $row->description);
        $this->assertSame($typed, $row->new_values['reason'], 'The stored reason stays as typed.');
    }

    /** @return array<string, array{0: string}> */
    public static function emptyReasons(): array
    {
        return ['a full stop' => ['.'], 'a question mark' => ['?'], 'marks and spaces' => ['. ! ?']];
    }

    #[DataProvider('emptyReasons')]
    public function test_a_reason_needs_a_letter_or_digit(string $typed): void
    {
        $this->actingAs($this->preparer)->putJson("/api/cda/reports/{$this->report->id}", ['fields' => [['field_key' => 'employment.employees_regular', 'value' => 2]]])->assertOk();
        $this->actingAs($this->preparer)->postJson("/api/cda/reports/{$this->report->id}/workflow", ['action' => 'submit_for_review'])->assertOk();

        $this->actingAs($this->approver)->postJson("/api/cda/reports/{$this->report->id}/workflow", ['action' => 'return', 'reason' => $typed])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reason.0', 'A reason needs at least one letter or number.');
        $this->assertSame(CdaReport::STATUS_FOR_REVIEW, $this->report->fresh()->status);
    }
}
