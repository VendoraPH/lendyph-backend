<?php

namespace Tests\Feature;

use App\Models\Borrower;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The words a user reads from the server. Field names in the responses stay
 * as they are; only the sentences change.
 */
class VisibleServerWordingTest extends TestCase
{
    use SetupLendyPH;

    private const SNAKE_CASE_WORD = '/\b[a-z]+_[a-z_]+\b/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_a_missing_member_reads_member_on_the_same_field(): void
    {
        $response = $this->postJson('/api/loans', [])->assertUnprocessable();

        $this->assertSame(['The member field is required.'], $response->json('errors.borrower_id'));
    }

    public function test_an_unknown_member_reads_member_on_the_same_field(): void
    {
        $response = $this->postJson('/api/collaterals', ['borrower_id' => 999999])->assertUnprocessable();

        $this->assertSame(['The selected member is invalid.'], $response->json('errors.borrower_id'));
    }

    public function test_a_co_maker_relationship_reads_member_on_the_same_field(): void
    {
        $borrower = Borrower::factory()->create();

        $response = $this->postJson("/api/borrowers/{$borrower->id}/co-makers", [
            'relationship_to_borrower' => str_repeat('x', 256),
        ])->assertUnprocessable();

        $this->assertSame(
            ['The relationship to member field must not be greater than 255 characters.'],
            $response->json('errors.relationship_to_borrower'),
        );
    }

    public function test_member_actions_answer_with_member(): void
    {
        $borrower = Borrower::factory()->create();

        $this->patchJson("/api/borrowers/{$borrower->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('message', 'Member deactivated successfully.');

        $this->patchJson("/api/borrowers/{$borrower->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('message', 'Member reactivated successfully.');

        $this->deleteJson("/api/borrowers/{$borrower->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Member deleted successfully.');
    }

    public function test_approving_a_registration_that_is_not_pending_reads_member(): void
    {
        $borrower = Borrower::factory()->create();

        $response = $this->patchJson("/api/borrowers/{$borrower->id}/approve-registration")
            ->assertUnprocessable();

        $this->assertSame('Only members with a pending registration can be approved.', $response->json('message'));
    }

    public function test_performance_note_reads_in_plain_words(): void
    {
        $note = $this->reportNote('/api/reports/performance', 'note');

        $this->assertDoesNotMatchRegularExpression(self::SNAKE_CASE_WORD, $note);
        $this->assertSame(
            'Released and collected figures cover the selected dates, and collected leaves out the insurance premium, which is counted separately. '
            .'Outstanding, overdue, at-risk and active member figures are as of today, over the whole book.',
            $note,
        );
    }

    public function test_collection_efficiency_note_reads_in_plain_words(): void
    {
        $note = $this->reportNote('/api/reports/collection-efficiency', 'note');

        $this->assertDoesNotMatchRegularExpression(self::SNAKE_CASE_WORD, $note);
        $this->assertSame(
            'Counted by period: collected is the principal and interest paid on the periods due in the selected dates (each branch, each due month), '
            .'capped at what each period owes, so no rate exceeds 100%. Penalty collected and total cash received are separate figures.',
            $note,
        );
    }

    public function test_share_capital_pledge_note_reads_in_plain_words(): void
    {
        $note = $this->reportNote('/api/reports/share-capital', 'subscription.note');

        $this->assertDoesNotMatchRegularExpression(self::SNAKE_CASE_WORD, $note);
        $this->assertSame(
            'Total subscribed per period is the sum of per-schedule pledges (15th / 30th / both), not a lump-sum subscription, '
            .'so it is not directly comparable to total paid in.',
            $note,
        );
    }

    public function test_exports_head_the_column_member(): void
    {
        foreach (['releases', 'repayments', 'due-past-due'] as $export) {
            $header = strtok($this->get("/api/reports/{$export}/export")->assertOk()->streamedContent(), "\n");

            $this->assertStringContainsString('Member', $header, $export);
            $this->assertStringNotContainsString('Borrower', $header, $export);
        }
    }

    private function reportNote(string $url, string $path): string
    {
        $today = now()->toDateString();

        return (string) $this->getJson("{$url}?date_from={$today}&date_to={$today}")
            ->assertOk()
            ->json("data.{$path}");
    }
}
