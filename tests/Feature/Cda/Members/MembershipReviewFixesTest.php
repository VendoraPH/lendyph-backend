<?php

namespace Tests\Feature\Cda\Members;

use App\Models\Borrower;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Traits\SetupLendyPH;

/**
 * The C1 review's member fixes:
 * - M4: only a member has a membership date; setting it the first time
 *   gives the membership number (the member code) when there is none;
 * - L5: the members list counts withdrawn and deceased members.
 */
class MembershipReviewFixesTest extends TestCase
{
    use SetupLendyPH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAndLogin();
    }

    public function test_an_applicant_gets_no_membership_date(): void
    {
        foreach (['pending', 'rejected'] as $status) {
            $person = Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => $status]);

            // The member update ignores it; the membership route refuses it.
            $this->putJson("/api/borrowers/{$person->id}", ['membership_date' => '2024-01-01'])->assertOk();
            $this->assertNull($person->fresh()->membership_date);

            $response = $this->patchJson("/api/borrowers/{$person->id}/membership", ['membership_date' => '2024-01-01'])
                ->assertUnprocessable()->assertJsonValidationErrors('status');

            $this->assertStringContainsString('Approve the registration', $response->json('errors.status.0'));
            $this->assertNull($person->fresh()->membership_date);
        }
    }

    public function test_setting_the_date_the_first_time_gives_the_membership_number(): void
    {
        $member = Borrower::factory()->create(['branch_id' => $this->branch->id]);
        DB::table('borrowers')->where('id', $member->id)->update(['membership_date' => null, 'membership_number' => null]);

        $this->patchJson("/api/borrowers/{$member->id}/membership", ['membership_date' => '2018-07-01'])->assertOk()
            ->assertJsonPath('data.membership_number', $member->borrower_code);

        DB::table('borrowers')->where('id', $member->id)->update(['membership_number' => 'KEEP-1']);
        $this->patchJson("/api/borrowers/{$member->id}/membership", ['membership_date' => '2018-08-01'])->assertOk()
            ->assertJsonPath('data.membership_number', 'KEEP-1');
    }

    public function test_the_members_list_counts_withdrawn_and_deceased(): void
    {
        Borrower::factory()->create(['branch_id' => $this->branch->id, 'status' => 'withdrawn']);
        Borrower::factory()->count(2)->create(['branch_id' => $this->branch->id, 'status' => 'deceased']);

        $stats = $this->getJson('/api/borrowers')->assertOk()->json('meta.stats');

        $this->assertSame(1, $stats['withdrawn']);
        $this->assertSame(2, $stats['deceased']);
    }
}
