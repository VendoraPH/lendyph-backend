<?php

namespace Tests\Feature\Phase6\Amla\Cases;

use App\Models\AmlaAlert;
use App\Models\AmlaAlertAction;
use App\Models\AmlaInboxItem;
use App\Models\AmlaLogEntry;
use App\Models\Branch;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The alert workflow (task §12; AMLA-B6): assign, review, escalate to a case
 * or close with a documented reason, each step in AMLA's own history, never
 * the main audit trail. An assignee is told in AMLA's own inbox, by
 * reference and priority only.
 */
class AmlaAlertWorkflowTest extends TestCase
{
    use CaseTestHelpers, SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startCases();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_alert_is_assigned_reviewed_and_closed_with_a_reason_in_amlas_own_history(): void
    {
        $alert = $this->structuringAlert();
        $officer = $this->amlaUser(['amla:alerts']);
        $assignee = $this->amlaUser(['amla:alerts']);
        $outside = $this->outsideAmlaCounts();

        $this->actingAs($officer)->postJson("/api/amla/alerts/{$alert->id}/assign", ['assignee_id' => $assignee->id])
            ->assertOk()->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.assigned_to.id', $assignee->id);
        $item = AmlaInboxItem::query()->where('user_id', $assignee->id)->sole();
        $this->assertSame("Alert {$alert->reference} (High priority) is assigned to you.", $item->message);

        $this->actingAs($assignee)->postJson("/api/amla/alerts/{$alert->id}/review", ['note' => 'Looking at the deposits.'])->assertOk()->assertJsonPath('data.status', 'under_review');
        $this->postJson("/api/amla/alerts/{$alert->id}/close")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson("/api/amla/alerts/{$alert->id}/close", ['reason' => 'Two separate salary deposits, documented by payslips.'])
            ->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.status_words', 'Closed with a documented reason');
        $this->postJson("/api/amla/alerts/{$alert->id}/close", ['reason' => 'Again.'])->assertConflict();

        $history = $this->getJson("/api/amla/alerts/{$alert->id}/history")->assertOk()->json('data');
        $this->assertSame(['assigned', 'review_started', 'closed'], array_column($history, 'action'));
        $this->assertSame('Two separate salary deposits, documented by payslips.', $history[2]['note']);
        $this->assertNotSame('Two separate salary deposits, documented by payslips.', AmlaAlertAction::query()->where('action', 'closed')->toBase()->value('note'));
        $this->assertNull($alert->fresh()->open_key);
        $this->assertSame(1, AmlaLogEntry::query()->where('action', 'alert_history_viewed')->count());
        $this->assertNothingWrittenOutsideAmla($outside);
    }

    public function test_an_alert_is_assigned_only_to_someone_who_holds_amla_alerts_and_sees_its_branch(): void
    {
        $alert = $this->structuringAlert();
        $officer = $this->amlaUser(['amla:alerts']);
        $other = Branch::factory()->create();
        $elsewhere = $this->amlaUser(['amla:alerts']);
        $elsewhere->branches()->sync([$other->id]);

        $this->actingAs($officer)->postJson("/api/amla/alerts/{$alert->id}/assign", ['assignee_id' => $this->amlaUser(['amla:cdd'])->id])->assertUnprocessable();
        $this->postJson("/api/amla/alerts/{$alert->id}/assign", ['assignee_id' => $elsewhere->id])->assertUnprocessable();

        $theirs = $this->structuringAlert($other->id);
        $this->postJson("/api/amla/alerts/{$theirs->id}/assign", ['assignee_id' => $officer->id])->assertNotFound();
        $this->actingAs($this->amlaUser(['amla:view']))->postJson("/api/amla/alerts/{$alert->id}/review")->assertForbidden();
    }

    public function test_escalating_opens_a_case_and_needs_case_access_and_head_office(): void
    {
        $alert = $this->structuringAlert();

        $this->actingAs($this->amlaUser(['amla:alerts', 'amla:cases']))->postJson("/api/amla/alerts/{$alert->id}/escalate", ['title' => 'Structuring'])->assertForbidden();

        $officer = $this->headOffice(['amla:alerts', 'amla:cases']);
        $case = $this->actingAs($officer)->postJson("/api/amla/alerts/{$alert->id}/escalate", ['title' => 'Structuring'])->assertCreated()->json('data');

        $this->assertSame('escalated', $alert->fresh()->status);
        $this->assertSame($alert->borrower_id, $case['subject']['id']);
        $this->assertSame([$alert->reference], array_column($case['alerts'], 'reference'));
        $this->postJson("/api/amla/alerts/{$alert->id}/close", ['reason' => 'Reviewed.'])->assertConflict();
    }

    public function test_a_rerun_never_clears_an_alert_a_person_is_working_on_and_a_closed_window_opens_afresh(): void
    {
        $alert = $this->structuringAlert();
        $officer = $this->amlaUser(['amla:alerts']);
        $this->actingAs($officer)->postJson("/api/amla/alerts/{$alert->id}/review")->assertOk();

        $alert->events()->first()->markReversed('repayment_void', 1, now());
        $this->detect();
        $this->assertSame('under_review', $alert->fresh()->status);

        $this->postJson("/api/amla/alerts/{$alert->id}/close", ['reason' => 'Reviewed.'])->assertOk();
        $this->event($alert->borrower, '300000.00');
        $this->event($alert->borrower, '300000.00');
        $this->detect();

        $this->assertSame(2, AmlaAlert::query()->where('borrower_id', $alert->borrower_id)->where('rule_code', 'A')->count());
    }
}
