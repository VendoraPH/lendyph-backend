<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * POST /loans/{loan}/print-log: printing a disclosure statement or a promissory
 * note leaves one row in the Audit Trail.
 */
class LoanPrintLogTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function documents(): array
    {
        return [
            'disclosure' => ['disclosure', 'Printed disclosure statement'],
            'promissory note' => ['promissory_note', 'Printed promissory note'],
        ];
    }

    #[DataProvider('documents')]
    public function test_it_writes_one_audit_row(string $document, string $description): void
    {
        $loan = $this->createReleasedLoan();
        $before = AuditLog::count();

        $this->postJson("/api/loans/{$loan->id}/print-log", ['document' => $document])
            ->assertCreated()
            ->assertExactJson(['logged' => true]);

        $this->assertSame($before + 1, AuditLog::count());

        $row = AuditLog::query()->latest('id')->firstOrFail();

        $this->assertSame('printed', $row->action);
        $this->assertSame(Loan::class, $row->auditable_type);
        $this->assertSame($loan->id, $row->auditable_id);
        $this->assertSame($description, $row->description);
        $this->assertSame($this->admin->id, $row->user_id);
    }

    public function test_it_changes_nothing_on_the_loan(): void
    {
        $loan = $this->createReleasedLoan();
        $before = $loan->fresh()->getAttributes();

        $this->postJson("/api/loans/{$loan->id}/print-log", ['document' => 'disclosure'])->assertCreated();

        $this->assertEquals($before, $loan->fresh()->getAttributes());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function badDocuments(): array
    {
        return [
            'missing' => [null],
            'unknown' => ['statement_of_account'],
            'array' => [['disclosure']],
        ];
    }

    #[DataProvider('badDocuments')]
    public function test_a_bad_document_answers_422_and_writes_nothing(mixed $document): void
    {
        $loan = $this->createReleasedLoan();
        $before = AuditLog::count();

        $this->postJson("/api/loans/{$loan->id}/print-log", ['document' => $document])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document');

        $this->assertSame($before, AuditLog::count());
    }

    /**
     * The log and the document endpoints it records must agree, status by
     * status, so a print is logged exactly where the document can be printed.
     *
     * @return array<string, array{0: string}>
     */
    public static function everyStatus(): array
    {
        $statuses = ['draft', 'for_review', 'approved', 'rejected', 'released', 'ongoing', 'completed', 'defaulted', 'restructured', 'void'];

        return array_combine($statuses, array_map(fn (string $status) => [$status], $statuses));
    }

    #[DataProvider('everyStatus')]
    public function test_each_status_answers_as_the_documents_own_endpoint_does(string $status): void
    {
        foreach (['disclosure' => 'disclosure', 'promissory_note' => 'promissory-note'] as $document => $path) {
            $loan = $this->createReleasedLoan();
            $loan->forceFill(['status' => $status])->save();

            $printable = $this->getJson("/api/loans/{$loan->id}/{$path}")->status() === 200;
            $before = AuditLog::count();

            $response = $this->postJson("/api/loans/{$loan->id}/print-log", ['document' => $document]);

            $response->assertStatus($printable ? 201 : 422);
            $this->assertSame($before + ($printable ? 1 : 0), AuditLog::count());
        }
    }

    public function test_a_draft_is_refused_with_a_message(): void
    {
        $loan = Loan::factory()->create(['status' => 'draft', 'branch_id' => $this->branch->id]);

        $this->postJson("/api/loans/{$loan->id}/print-log", ['document' => 'disclosure'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('document');
    }

    public function test_it_needs_loans_view(): void
    {
        $loan = $this->createReleasedLoan();

        $this->actingAs(User::factory()->inBranches($this->branch)->create());

        $this->postJson("/api/loans/{$loan->id}/print-log", ['document' => 'disclosure'])->assertForbidden();
    }

    public function test_another_branchs_loan_answers_404_and_writes_nothing(): void
    {
        $far = Branch::factory()->create(['name' => 'Far Branch', 'code' => 'FAR']);
        $loan = $this->createReleasedLoan();
        $loan->update(['branch_id' => $far->id]);

        $user = User::factory()->inBranches($this->branch)->create();
        $user->givePermissionTo(Permission::findByName('loans:view'));
        $this->actingAs($user);

        $before = AuditLog::count();

        $this->postJson("/api/loans/{$loan->id}/print-log", ['document' => 'disclosure'])->assertNotFound();

        $this->assertSame($before, AuditLog::count());
    }
}
