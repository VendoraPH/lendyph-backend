<?php

namespace App\Services;

use App\Models\Borrower;
use App\Models\BorrowerNotificationPreference;
use App\Models\CoMaker;
use App\Models\CreditApplicationProfile;
use App\Models\CreditDecision;
use App\Models\ReminderPause;
use App\Models\User;
use Illuminate\Support\Arr;

/**
 * The personal fields of a member, a co-maker, a member's credit
 * application and a reminder pause, by the round fz personal-data rule: they need `borrowers:view`
 * and the member's own branch (ContactVisibility). One list for every place
 * that leaves them out: a co-maker shown slim (CoMakerResource::slim()) and the
 * Audit Trail's list, detail and CSV, whose rows store the whole record.
 *
 * A member's record is the widest, so its rows keep only the fields listed in
 * MEMBER_KEPT: a column added to it later stays hidden until it is listed.
 * The other kinds list the fields they leave out, and include columns a
 * record no longer has, so audit rows written before a column was dropped are
 * covered too.
 */
final class PersonalDataKeys
{
    /**
     * The only fields a member's audit row keeps for a caller who may not read
     * the member: who they are, their branch and their registration status.
     * Everything else is left out, the external account number and the pledge
     * amount included, as the CSV importer's redactor and the short member
     * view treat them.
     */
    public const MEMBER_KEPT = [
        'id', 'borrower_code', 'first_name', 'middle_name', 'last_name', 'suffix', 'branch_id', 'status',
        'approved_at', 'approved_by', 'rejected_at', 'rejected_by', 'created_at', 'updated_at',
    ];

    /**
     * A co-maker's address, contact number, work and income. Name, code and
     * relationship stay.
     */
    public const CO_MAKER = ['address', 'contact_number', 'occupation', 'employer', 'monthly_income'];

    /**
     * What a provider or a staff member wrote about why a member's mobile
     * number or email was marked invalid. The numbers themselves are never
     * stored in the trail (the model's `$auditRedacted`).
     */
    public const NOTIFICATION_PREFERENCE = ['mobile_invalid_reason', 'email_invalid_reason'];

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

    /**
     * What staff wrote about why reminders were paused: on a member's or a
     * loan's pause it is often about the member (hospital, a death in the
     * family). The reminder screens hide a member pause's reason the same way
     * (ReminderPauseResource). An update's new values hold only what changed,
     * so the row can't always tell whose pause it was: every pause's reason is
     * left out.
     */
    public const REMINDER_PAUSE = ['reason'];

    /**
     * The fields each kind of audit target leaves out.
     */
    private const LEFT_OUT_BY_AUDIT_TARGET = [
        CoMaker::class => self::CO_MAKER,
        BorrowerNotificationPreference::class => self::NOTIFICATION_PREFERENCE,
        CreditDecision::class => self::CREDIT_DECISION,
        CreditApplicationProfile::class => self::CREDIT_APPLICATION_PROFILE,
        ReminderPause::class => self::REMINDER_PAUSE,
    ];

    /**
     * Audit targets whose personal fields also need `credit_scoring:view`, as
     * the credit scoring screens do.
     */
    private const CREDIT_TARGETS = [CreditDecision::class, CreditApplicationProfile::class];

    /**
     * Whether $user reads a member's personal fields in audit rows: the round
     * fz rule with no branch to prove, so only a caller who sees every branch
     * qualifies. The Audit Trail is `branches:view_all` only today; were a
     * branch user given it, they would be refused here by default.
     */
    public static function auditReaderSeesThem(?User $user): bool
    {
        return app(ContactVisibility::class)->allows($user, null);
    }

    /**
     * An audit row's stored old or new values as $user may read them: without
     * the personal fields of its target's kind, unless $user may read them.
     * Any other target's values are returned unchanged.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public static function auditValuesFor(?User $user, ?string $auditableType, ?array $values): ?array
    {
        if ($values === null || ! self::coversAuditTarget($auditableType) || self::seesAuditTarget($user, $auditableType)) {
            return $values;
        }

        return $auditableType === Borrower::class
            ? Arr::only($values, self::MEMBER_KEPT)
            : Arr::except($values, self::LEFT_OUT_BY_AUDIT_TARGET[$auditableType]);
    }

    private static function coversAuditTarget(?string $auditableType): bool
    {
        return $auditableType === Borrower::class || isset(self::LEFT_OUT_BY_AUDIT_TARGET[$auditableType]);
    }

    private static function seesAuditTarget(?User $user, string $auditableType): bool
    {
        return self::auditReaderSeesThem($user)
            && (! in_array($auditableType, self::CREDIT_TARGETS, true) || $user->can('credit_scoring:view'));
    }
}
