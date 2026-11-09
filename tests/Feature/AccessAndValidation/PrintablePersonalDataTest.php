<?php

namespace Tests\Feature\AccessAndValidation;

use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\Loan;
use App\Models\User;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The disclosure and promissory note answer to `loans:view`, so their payloads
 * carry only what the printed documents use (the frontend's disclosure.ts and
 * promissory-note.ts templates), never the rest of a person's record. The
 * note's addresses also need member access (`borrowers:view` and the member's
 * branch), which the manager here lacks.
 */
class PrintablePersonalDataTest extends TestCase
{
    use SetupLendyPH;

    private Loan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();

        $this->loan = $this->createReleasedLoan();
        $this->loan->borrower->update([
            'address' => '12 Mabini St',
            'email' => 'private@example.test',
            'monthly_income' => 25000,
        ]);

        $coMaker = CoMaker::factory()->create([
            'borrower_id' => $this->loan->borrower_id,
            'address' => '9 Rizal Ave',
            'relationship_to_borrower' => 'Sister',
        ]);
        $this->loan->coMakers()->attach($coMaker->id);

        $manager = User::factory()->inBranches($this->branch)->create();
        $manager->assignRole('manager');
        $this->actingAs($manager);
    }

    public function test_the_promissory_note_sends_only_what_it_prints(): void
    {
        $data = $this->getJson("/api/loans/{$this->loan->id}/promissory-note")->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['borrower_code', 'full_name', 'address'], array_keys($data['borrower']));
        $this->assertNull($data['borrower']['address']);
        $this->assertSame($this->loan->borrower->full_name, $data['borrower']['full_name']);

        $this->assertEqualsCanonicalizing(
            ['co_maker_code', 'full_name', 'address', 'relationship_to_borrower'],
            array_keys($data['co_makers'][0]),
        );
        $this->assertNull($data['co_makers'][0]['address']);
        $this->assertSame('Sister', $data['co_makers'][0]['relationship_to_borrower']);

        $this->actingAs($this->admin);
        $full = $this->getJson("/api/loans/{$this->loan->id}/promissory-note")->assertOk()->json('data');
        $this->assertSame('12 Mabini St', $full['borrower']['address']);
        $this->assertSame('9 Rizal Ave', $full['co_makers'][0]['address']);
    }

    public function test_the_disclosure_sends_only_what_it_prints(): void
    {
        $data = $this->getJson("/api/loans/{$this->loan->id}/disclosure")->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['borrower_code', 'full_name'], array_keys($data['borrower']));
        $this->assertEqualsCanonicalizing(['full_name'], array_keys($data['co_makers'][0]));
    }

    public function test_the_removed_fields_appear_nowhere_in_either_document(): void
    {
        foreach (['promissory-note', 'disclosure'] as $document) {
            $body = $this->getJson("/api/loans/{$this->loan->id}/{$document}")->assertOk()->getContent();

            foreach ([$this->loan->borrower->contact_number, 'private@example.test', 'monthly_income', 'birthdate', 'employer'] as $absent) {
                $this->assertStringNotContainsString($absent, $body, "{$document}: {$absent}");
            }
        }
    }

    /**
     * A member saved with only `street_address` has a blank `address`, and the
     * note must still say where they live.
     */
    public function test_the_note_falls_back_to_the_street_address_for_a_stored_blank_address(): void
    {
        Borrower::whereKey($this->loan->borrower_id)->update(['address' => null, 'street_address' => '44 Luna St']);

        $this->actingAs($this->admin);
        $this->getJson("/api/loans/{$this->loan->id}/promissory-note")
            ->assertOk()
            ->assertJsonPath('data.borrower.address', '44 Luna St');
    }
}
