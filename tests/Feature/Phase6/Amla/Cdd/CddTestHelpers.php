<?php

namespace Tests\Feature\Phase6\Amla\Cdd;

use App\Models\Borrower;
use App\Models\Branch;
use App\Models\Document;
use App\Models\GCashNonMember;
use App\Models\User;
use Tests\Feature\Phase6\Amla\AmlaTestHelpers;

/**
 * Shared set-up for the AMLA CDD and risk tests (AMLA-B4): members in the
 * seeded branch and in another one, their valid IDs, walk-ins, and the users
 * who work them (never super_admin).
 */
trait CddTestHelpers
{
    use AmlaTestHelpers;

    /** A CDD officer in the seeded branch only: `amla:cdd`, no head-office access. */
    protected function cddOfficer(): User
    {
        return $this->amlaUser(['amla:cdd']);
    }

    /** A head-office CDD officer: `amla:cdd` and `branches:view_all`. */
    protected function headOfficeCddOfficer(): User
    {
        return $this->amlaUser(['amla:cdd', 'branches:view_all']);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function member(array $attributes = [], ?Branch $branch = null): Borrower
    {
        return Borrower::factory()->create([
            'branch_id' => ($branch ?? $this->branch)->id,
            'city' => 'Cebu City',
            'province' => 'Cebu',
            'status' => 'active',
            ...$attributes,
        ]);
    }

    protected function otherBranch(): Branch
    {
        return Branch::factory()->create();
    }

    protected function validId(Borrower $member, string $number = 'P1234567A', string $label = 'passport', ?string $customTypeName = null): Document
    {
        return Document::query()->create([
            'documentable_type' => Borrower::class,
            'documentable_id' => $member->id,
            'type' => 'valid_id',
            'label' => $label,
            'custom_type_name' => $customTypeName,
            'id_number' => $number,
            'side' => 'front',
            'file_path' => 'borrowers/'.$member->id.'/passport.jpg',
            'original_filename' => 'passport.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 1024,
            'uploaded_by' => $this->admin->id,
        ]);
    }

    protected function walkIn(bool $withId = true): GCashNonMember
    {
        $factory = GCashNonMember::factory();

        return ($withId ? $factory : $factory->withoutId())->create();
    }

    /**
     * The KYC answers that complete a member's AMLA profile.
     *
     * @return array<string, mixed>
     */
    protected function completeProfile(): array
    {
        return [
            'nationality' => 'PH',
            'occupation' => 'Teacher',
            'source_of_funds' => 'salary',
            'purpose' => 'Savings and loans with the cooperative',
            'expected_monthly_amount' => '15000.00',
            'expected_monthly_count' => 4,
            'pep_status' => 'not_pep',
            'adverse_info' => false,
        ];
    }

    protected function cddUrl(Borrower|GCashNonMember $subject, string $suffix = ''): string
    {
        $type = $subject instanceof Borrower ? 'members' : 'walk-ins';

        return "/api/amla/cdd/{$type}/{$subject->id}{$suffix}";
    }
}
