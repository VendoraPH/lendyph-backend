<?php

namespace App\Services\Amla\Foundation;

use App\Models\AmlaLogEntry;
use App\Models\User;
use InvalidArgumentException;

/**
 * Writes AMLA's own log (AMLA design §3.6, §4.14): every AMLA change, every
 * view of a sensitive AMLA record and every refused attempt. Never the main
 * audit trail. Rows are kept forever (AmlaLogEntry).
 *
 * Details name what happened, never a member's personal data: later slices
 * log a case, STR or match by its id and reference only.
 */
final class AmlaLog
{
    /** @var array<string, string> each action in words; later slices add theirs here */
    public const ACTIONS = [
        'access_denied' => 'Access refused',
        'log_viewed' => 'AMLA log viewed',
        'change_requested' => 'Change asked for',
        'change_approved' => 'Change approved',
        'change_rejected' => 'Change rejected',
        'change_withdrawn' => 'Change withdrawn',
        'cdd_list_viewed' => 'CDD worklist viewed',
        'cdd_profile_viewed' => 'CDD profile viewed',
        'cdd_profile_updated' => 'CDD profile updated',
        'cdd_id_verified' => 'ID verification recorded',
        'cdd_risk_assessed' => 'Risk assessed',
        'cdd_review_recorded' => 'CDD review recorded',
        'history_screened' => 'Transaction history screened',
        'watchlist_uploaded' => 'Watchlist uploaded',
        'screening_rescreen_requested' => 'Rescreen asked for',
        'screening_run' => 'Screening run',
        'screening_matches_listed' => 'Screening matches listed',
        'screening_match_viewed' => 'Screening match viewed',
        'screening_match_confirmed' => 'Match confirmed',
        'screening_match_cleared' => 'Match cleared as a false positive',
        'orders_listed' => 'Orders listed',
        'order_viewed' => 'Order viewed',
        'order_placed' => 'Order recorded and holds placed',
        'order_credit_held' => 'Money paid in under an order held',
        'order_release_requested' => 'Order release asked for',
        'order_released' => 'Order release approved and holds lifted',
        'order_release_rejected' => 'Order release rejected',
        'order_release_withdrawn' => 'Order release withdrawn',
        'cdd_valid_ids_viewed' => 'Member IDs viewed (masked)',
        'alerts_viewed' => 'Alerts viewed',
        'alert_viewed' => 'Alert viewed',
        'ctr_candidates_viewed' => 'CTR candidates viewed',
        'ctr_candidate_viewed' => 'CTR candidate viewed',
        'detection_rules_viewed' => 'Detection rules viewed',
        'alert_assigned' => 'Alert assigned',
        'alert_review_started' => 'Alert review started',
        'alert_escalated' => 'Alert escalated to a case',
        'alert_closed' => 'Alert closed',
        'alert_history_viewed' => 'Alert history viewed',
        'cases_viewed' => 'Cases viewed',
        'case_viewed' => 'Case viewed',
        'case_opened' => 'Case opened',
        'case_assigned' => 'Case assigned',
        'case_note_added' => 'Case note added',
        'case_evidence_added' => 'Case evidence added',
        'case_evidence_downloaded' => 'Case evidence downloaded',
        'case_decided' => 'Case decision recorded',
        'reports_viewed' => 'Reports viewed',
        'report_viewed' => 'Report viewed',
        'report_prepared' => 'Report prepared',
        'report_approved' => 'Report approved',
        'report_returned' => 'Report returned',
        'report_submission_recorded' => 'Filing step recorded',
        'report_exported' => 'Report exported',
        'ctr_set_aside_requested' => 'CTR candidate set-aside asked for',
        'ctr_set_aside_approved' => 'CTR candidate set aside',
        'ctr_set_aside_rejected' => 'CTR candidate set-aside refused',
        'report_evidence_downloaded' => 'Filing evidence downloaded',
        'amla_report_viewed' => 'AMLA report viewed',
        'amla_report_exported' => 'AMLA report exported',
        'amla_report_export_downloaded' => 'AMLA report export downloaded',
        'amla_dashboard_viewed' => 'AMLA dashboard viewed',
        'set_aside_backfilled' => 'Waiting set-aside moved to its own record',
        'filing_deadlines_viewed' => 'Filing deadlines viewed',
        'risk_downgrade_requested' => 'High-risk downgrade asked for',
        'risk_downgrade_approved' => 'High-risk downgrade approved',
        'risk_downgrade_rejected' => 'High-risk downgrade rejected',
        'risk_downgrades_viewed' => 'High-risk downgrades viewed',
    ];

    /** @var array<string, string> each subject type in words, read as "<words> #<id>" */
    public const SUBJECTS = [
        'change_request' => 'Change request',
        'settings_version' => 'Settings version',
        'calendar_entry' => 'Calendar day',
        'calendar_year' => 'Calendar year',
        'member' => 'Member',
        'walk_in' => 'Walk-in',
        'co_maker' => 'Co-maker',
        'watchlist_version' => 'Watchlist version',
        'screening_match' => 'Screening match',
        'order' => 'Order',
        'alert' => 'Alert',
        'ctr_candidate' => 'CTR candidate',
        'case' => 'Case',
        'report' => 'Report',
        'risk_downgrade' => 'High-risk downgrade',
        'amla_export' => 'AMLA report export',
    ];

    private const USER_AGENT_MAX = 255;

    /**
     * One log row. A null user is the system. The request's address and
     * browser are kept when there is a request.
     *
     * @param  array<string, mixed>  $details
     */
    public function record(string $action, ?User $user, ?string $subjectType = null, ?int $subjectId = null, array $details = []): AmlaLogEntry
    {
        if (! array_key_exists($action, self::ACTIONS)) {
            throw new InvalidArgumentException("\"{$action}\" isn't an AMLA log action.");
        }

        if ($subjectType !== null && ! array_key_exists($subjectType, self::SUBJECTS)) {
            throw new InvalidArgumentException("\"{$subjectType}\" isn't an AMLA log subject.");
        }

        $request = app()->bound('request') ? request() : null;

        return AmlaLogEntry::query()->create([
            'action' => $action,
            'actor_type' => $user === null ? 'system' : 'user',
            'user_id' => $user?->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'details' => $details === [] ? null : $details,
            'ip_address' => $request?->ip(),
            'user_agent' => self::clean($request?->userAgent()),
        ]);
    }

    public static function actionWords(string $action): string
    {
        return self::ACTIONS[$action] ?? ucfirst(str_replace('_', ' ', $action));
    }

    public static function subjectWords(?string $type, ?int $id): ?string
    {
        if ($type === null) {
            return null;
        }

        $words = self::SUBJECTS[$type] ?? ucfirst(str_replace('_', ' ', $type));

        return $id === null ? $words : "{$words} #{$id}";
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr(mb_scrub($value, 'UTF-8'), 0, self::USER_AGENT_MAX, 'UTF-8');
    }
}
