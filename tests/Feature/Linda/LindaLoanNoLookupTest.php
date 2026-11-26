<?php

namespace Tests\Feature\Linda;

use App\Models\Borrower;
use App\Models\LindaQuestion;
use App\Models\Loan;
use App\Models\User;
use App\Services\Linda\LindaWording;
use App\Services\Linda\Model\FakeModel;
use Exception;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Linda finds an imported loan by its file's Loan No. (`loans.external_loan_no`,
 * finish run round 6), as she finds LN- numbers: a question holding a whole
 * value equal to a Loan No. of this deployment, any case, is a loan lookup,
 * answered on the server with `loans:view`, the branch scope and the member
 * by name and code only, and never sent to the model. The check reads the
 * database before any model call and fails closed. A value that matches no
 * Loan No. falls to the rules before it.
 */
class LindaLoanNoLookupTest extends LindaAnswersTestCase
{
    private const URL = '/api/linda/chat';

    private User $asker;

    private FakeModel $fake;

    private Borrower $juan;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('linda.enabled', true);
        config()->set('linda.driver', 'fake');
        config()->set('linda.model', 'claude-haiku-5-5');
        config()->set('linda.monthly_cap', '1000.00');
        config()->set('linda.usd_to_php', '62.00');
        $this->fake = new FakeModel;
        $this->app->instance(FakeModel::class, $this->fake);
        $this->asker = $this->headOffice(['reports:view', 'loans:view', 'borrowers:view', 'payments:view', 'linda:use']);

        $this->juan = $this->member($this->branch, 'Juan', 'Dela Cruz');
        $this->loan = $this->releaseTo($this->juan, 60000);
        $this->loan->forceFill(['external_loan_no' => 'L-2019-0042'])->save();
    }

    /** @return array<string, array{string, string}> */
    public static function questions(): array
    {
        return [
            'en' => ['What is the status of L-2019-0042?', 'L-2019-0042'],
            'any case, in brackets' => ['Status of (l-2019-0042)', 'L-2019-0042'],
            'alone' => ['L-2019-0042', 'L-2019-0042'],
            'tl' => ['Kumusta ang utang L-2019-0042?', 'L-2019-0042'],
            'ceb' => ['Pila pa ang balanse sa L-2019-0042?', 'L-2019-0042'],
            'a short code' => ['How about D-1?', 'D-1'],
            'a bare number after loan' => ['Status of loan 42', '42'],
            'with spaces' => ['Status of BC 2019 07', 'BC 2019 07'],
            'a possessive, with spaces' => ["What is BC 2019 07's status?", 'BC 2019 07'],
            'slash-joined' => ['How about L-2019-0042/L-2019-0043?', 'L-2019-0042'],
            'no digit, after loan' => ['Status of loan ABC-XYZ', 'ABC-XYZ'],
            'no digit, any case' => ['status of loan abc-xyz?', 'ABC-XYZ'],
            'glued to loan no.' => ['Loan No.BC-07 status?', 'BC-07'],
            'glued to loan #' => ['How about loan#ABC-XYZ?', 'ABC-XYZ'],
            'glued to loan no:' => ['Loan No:BC-07', 'BC-07'],
            'glued, no digit, tl' => ['Kumusta ang utang#ABC-XYZ?', 'ABC-XYZ'],
            'glued, with spaces' => ['Loan No.BC 2019 07', 'BC 2019 07'],
            'with spaces, no digit' => ['Status of loan ABC XYZ', 'ABC XYZ'],
        ];
    }

    #[DataProvider('questions')]
    public function test_a_loan_no_is_a_loan_lookup_answered_on_the_server(string $question, string $loanNo): void
    {
        $this->loan->forceFill(['external_loan_no' => $loanNo])->save();

        $response = $this->actingAs($this->asker)->postJson(self::URL, ['message' => $question])->assertOk();

        $this->assertSame([], $this->fake->requests, 'The model was asked: '.$question);
        $this->assertSame('loan_details', $response->json('data.intent'));
        $answer = (string) $response->json('data.answer');
        $this->assertStringContainsString((string) $this->loan->loan_account_number." (Loan No. {$loanNo})", $answer);
        $this->assertStringContainsString($this->juan->borrower_code, $answer);

        $row = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame(LindaQuestion::SOURCE_LOOKUP, $row->source);
        $this->assertSame('answered', $row->outcome);
        $this->assertSame([$this->loan->id], $row->loan_ids);
        $this->assertSame([$this->juan->id], $row->member_ids);
        $this->assertNull($row->model);
    }

    public function test_the_apps_own_loan_number_wins_over_a_file_loan_no_written_like_it(): void
    {
        $this->loan->forceFill(['external_loan_no' => 'LN-0042'])->save();
        $own = $this->releaseTo($this->member($this->branch, 'Maria', 'Santos'), 30000);
        $own->forceFill(['loan_account_number' => 'LN-000042'])->save();

        $response = $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'Status of LN-0042'])->assertOk();

        $this->assertSame([], $this->fake->requests);
        $this->assertStringStartsWith('LN-000042 for ', (string) $response->json('data.answer'));
        $this->assertSame([$own->id], LindaQuestion::query()->latest('id')->firstOrFail()->loan_ids);
    }

    /** @return array<string, array{string, string}> */
    public static function lineBreaks(): array
    {
        return [
            'a line feed' => ["How about PN\n42?", 'PN 42'],
            'a carriage return and line feed' => ["How about LN\r\n0042?", 'LN 0042'],
            'a carriage return' => ["How about PN\r42?", 'PN 42'],
        ];
    }

    /**
     * Shift+Enter or a pasted cell puts a line break between a prefix and its
     * number; it reads as a space, so the question stays on the server.
     */
    #[DataProvider('lineBreaks')]
    public function test_a_line_break_between_a_prefix_and_its_number_stays_on_the_server(string $question, string $loanNo): void
    {
        $this->loan->forceFill(['external_loan_no' => $loanNo])->save();

        $this->actingAs($this->asker)->postJson(self::URL, ['message' => $question])->assertOk();

        $this->assertSame([], $this->fake->requests, 'The model was asked: '.json_encode($question));
        $this->assertSame(LindaQuestion::SOURCE_LOOKUP, LindaQuestion::query()->latest('id')->firstOrFail()->source);
    }

    public function test_another_branchs_loan_no_reads_as_not_found_and_stays_on_the_server(): void
    {
        $user = $this->userWith(['loans:view', 'borrowers:view', 'linda:use'], $this->far);

        $response = $this->actingAs($user)->postJson(self::URL, ['message' => 'Status of L-2019-0042'])->assertOk();

        $this->assertSame([], $this->fake->requests);
        $this->assertSame(LindaWording::noLoanNumbered('L-2019-0042'), $response->json('data.answer'));
        $this->assertSame('not_found', LindaQuestion::query()->latest('id')->firstOrFail()->outcome);
    }

    public function test_without_loans_view_the_lookup_is_refused_and_stays_on_the_server(): void
    {
        $user = $this->headOffice(['reports:view', 'borrowers:view', 'linda:use']);

        $response = $this->actingAs($user)->postJson(self::URL, ['message' => 'Status of L-2019-0042'])->assertOk();

        $this->assertSame([], $this->fake->requests);
        $this->assertSame('denied', LindaQuestion::query()->latest('id')->firstOrFail()->outcome);
        $this->assertStringNotContainsString($this->juan->borrower_code, (string) $response->json('data.answer'));
    }

    public function test_a_value_matching_no_loan_no_falls_to_the_rules_before_it(): void
    {
        $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'How about D-9?'])->assertOk();

        $this->assertCount(1, $this->fake->requests, 'no Loan No. matched, so the model reads it as before');
    }

    /** A Loan No. with no digit is found only after a loan word (round 7): no new match without one. */
    public function test_a_loan_no_with_no_digit_and_no_loan_word_is_not_a_lookup(): void
    {
        $this->loan->forceFill(['external_loan_no' => 'ABC-XYZ'])->save();

        $response = $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'What is the status of ABC-XYZ?'])->assertOk();

        $this->assertNotSame('loan_details', $response->json('data.intent'));
        $this->assertStringNotContainsString('(Loan No. ABC-XYZ)', (string) $response->json('data.answer'));
        $this->assertEmpty(LindaQuestion::query()->latest('id')->firstOrFail()->loan_ids);
    }

    /** A Loan No. holding a digit outranks a longer one without (round 7 review). */
    public function test_a_loan_no_with_a_digit_wins_over_a_longer_one_without(): void
    {
        $this->loan->forceFill(['external_loan_no' => 'BC-07'])->save();
        $other = $this->releaseTo($this->member($this->branch, 'Maria', 'Santos'), 30000);
        $other->forceFill(['external_loan_no' => 'SUMMARY'])->save();

        $response = $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'loan summary for loan BC-07'])->assertOk();

        $this->assertSame([], $this->fake->requests);
        $this->assertStringContainsString('(Loan No. BC-07)', (string) $response->json('data.answer'));
        $this->assertSame([$this->loan->id], LindaQuestion::query()->latest('id')->firstOrFail()->loan_ids);
    }

    public function test_a_later_question_sends_the_model_no_loan_no(): void
    {
        $this->actingAs($this->asker)->postJson(self::URL, [
            'message' => 'Tell me a joke',
            'history' => [
                ['role' => 'user', 'content' => 'Status of L-2019-0042', 'intent' => null],
                ['role' => 'assistant', 'content' => '', 'intent' => 'loan_details'],
            ],
        ])->assertOk();

        $this->assertCount(1, $this->fake->requests);
        $this->assertStringNotContainsString('L-2019-0042', serialize($this->fake->requests));
    }

    public function test_a_check_that_cant_read_the_loans_fails_closed(): void
    {
        DB::connection()->beforeExecuting(function (string $query): void {
            if (str_contains($query, 'external_loan_no')) {
                throw new Exception('down');
            }
        });

        $this->actingAs($this->asker)->postJson(self::URL, ['message' => 'Status of L-2019-0042'])
            ->assertStatus(503);

        $this->assertSame([], $this->fake->requests);
        $row = LindaQuestion::query()->latest('id')->firstOrFail();
        $this->assertSame(['lookup', 'error'], [$row->source, $row->outcome]);
    }
}
