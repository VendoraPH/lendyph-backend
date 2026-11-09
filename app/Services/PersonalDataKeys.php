<?php

namespace App\Services;

use App\Models\Borrower;
use App\Models\CoMaker;
use App\Models\CreditApplicationProfile;
use App\Models\CreditDecision;
use App\Models\User;
use Illuminate\Support\Arr;

/**
 * The personal fields of a member, a co-maker and a member's credit
 * application, by the round fz personal-data rule: they need `borrowers:view`
 * (and, where a record has one, the member's own branch). One list for every
 * place that leaves them out: a co-maker shown slim (CoMakerResource::slim())
 * and the Audit Trail's list, detail and CSV, whose rows store the whole
 * record.
 *
 * The keys include columns a record no longer has, so audit rows written
 * before a column was dropped are covered too.
 */
final class PersonalDataKeys
{
    /**
     * A member's personal details, their spouse's, and the free-text reason a
     * registration was refused. Name, code, branch and status stay.
     */
    public const MEMBER = [
        'birthdate', 'civil_status', 'gender',
        'address', 'street_address', 'barangay', 'city', 'province',
        'contact_number', 'email', 'employer_or_business', 'monthly_income', 'date_hired', 'photo_path',
        'spouse_first_name', 'spouse_middle_name', 'spouse_last_name', 'spouse_contact_number', 'spouse_occupation',
        'rejection_reason',
    ];

    /**
     * A co-maker's address, contact number, work and income. Name, code and
     * relationship stay.
     */
    public const CO_MAKER = ['address', 'contact_number', 'occupation', 'employer', 'monthly_income'];

    /**
     * A credit decision's reasons, and the score and recommendation it kept,
     * which carry the member's capacity worked out from their income. The
     * decision, who made it and when stay.
     */
    public const CREDIT_DECISION = ['reason', 'remarks', 'score_snapshot', 'recommendation_snapshot'];

    /**
     * A credit application's income, expense and debt figures and the details
     * scored with them. The notice acknowledgement stays.
     */
    public const CREDIT_APPLICATION_PROFILE = [
        'other_income', 'business_income', 'household_expenses', 'outside_debt_payments', 'outside_lenders_count',
        'employment_status', 'business_start_date', 'years_at_residence', 'verified_references_count',
        'income_verification',
    ];

    private const BY_AUDIT_TARGET = [
        Borrower::class => self::MEMBER,
        CoMaker::class => self::CO_MAKER,
        CreditDecision::class => self::CREDIT_DECISION,
        CreditApplicationProfile::class => self::CREDIT_APPLICATION_PROFILE,
    ];

    /**
     * Whether $user reads audit rows' personal fields. The Audit Trail is
     * `branches:view_all` only, so `borrowers:view` is the whole rule there.
     */
    public static function auditReaderSeesThem(?User $user): bool
    {
        return $user !== null && $user->can('borrowers:view');
    }

    /**
     * An audit row's stored old or new values without the personal fields of
     * its target's kind. Any other target's values are returned unchanged.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public static function withoutThemForAuditTarget(?string $auditableType, ?array $values): ?array
    {
        $keys = self::BY_AUDIT_TARGET[$auditableType] ?? [];

        return $values === null || $keys === [] ? $values : Arr::except($values, $keys);
    }
}
