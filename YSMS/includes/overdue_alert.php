<?php
/**
 * Overdue alert on the vessel details pages (display only — no emails, jobs or new tables).
 *
 *  - Pending Vessel (vessel_detail.php): "Survey Pending — N days since appointment date"
 *    once 7+ calendar days have passed since the Appointment Date (appointment_date).
 *  - Pending Report (report_detail.php): "Report Overdue — N days since survey completion"
 *    once 4+ calendar days have passed since the Survey Completed Date (survey_completed_date).
 *
 * Calendar days in the app's time zone (Asia/Kolkata, config.php). Driven by the current
 * status only, so it disappears as soon as the survey / report is completed or the vessel is
 * cancelled. Missing, invalid or future dates show nothing. Admin / Super Admin and the
 * assigned surveyor see it (the pages already refuse anyone else except the vessel's own
 * client, who doesn't get the alert).
 *
 * Usage: set $overdueAlertKind ('survey' on vessel_detail.php, 'report' on report_detail.php),
 * then include with $survey (the surveys row) and $role set.
 */

const OVERDUE_SURVEY_DAYS = 7;
const OVERDUE_REPORT_DAYS = 4;

/** Whole calendar days from $date (DATE / DATETIME string) to today; null when missing or invalid. */
function overdueCalendarDays($date): ?int {
    $d = substr(trim((string)$date), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $d === '0000-00-00') return null;
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    if (!$start || $start->format('Y-m-d') !== $d) return null;
    return (int)$start->diff(new DateTimeImmutable('today'))->format('%r%a');
}

/** ['kind' => 'survey'|'report', 'days' => int, 'since' => 'Y-m-d'] or null. */
function overdueAlertFor(array $survey, string $role): ?array {
    if (!in_array($role, ['Admin', 'Super Admin', 'Surveyor'], true)) return null;
    $status = (string)($survey['status'] ?? '');
    if ($status === 'Pending Vessel') {
        $days = overdueCalendarDays($survey['appointment_date'] ?? null);
        return $days !== null && $days >= OVERDUE_SURVEY_DAYS
            ? ['kind' => 'survey', 'days' => $days, 'since' => substr((string)$survey['appointment_date'], 0, 10)] : null;
    }
    if ($status === 'Pending Report') {
        $days = overdueCalendarDays($survey['survey_completed_date'] ?? null);
        return $days !== null && $days >= OVERDUE_REPORT_DAYS
            ? ['kind' => 'report', 'days' => $days, 'since' => substr((string)$survey['survey_completed_date'], 0, 10)] : null;
    }
    return null;
}

$overdueAlert = overdueAlertFor($survey ?? [], (string)($role ?? ''));
if ($overdueAlert && $overdueAlert['kind'] === ($overdueAlertKind ?? '')):
    $oaSurvey = $overdueAlert['kind'] === 'survey';
?>
<div class="overdue-alert <?= $oaSurvey ? 'overdue-alert-survey' : 'overdue-alert-report' ?>" role="status" data-testid="overdue-alert-<?= $overdueAlert['kind'] ?>">
    <i class="fa-solid <?= $oaSurvey ? 'fa-hourglass-half' : 'fa-triangle-exclamation' ?>" aria-hidden="true"></i>
    <span><b><?= $oaSurvey ? 'Survey Pending' : 'Report Overdue' ?></b> — <?= (int)$overdueAlert['days'] ?> days since <?= $oaSurvey ? 'appointment date' : 'survey completion' ?>
        <span class="overdue-alert-date">(<?= date('d M Y', strtotime($overdueAlert['since'])) ?>)</span></span>
</div>
<style>
.overdue-alert { display: flex; align-items: flex-start; gap: 8px; margin: 12px 16px 0; padding: 8px 12px; border-radius: 10px; font-size: 13px; line-height: 1.4; border: 1px solid; }
.overdue-alert i { margin-top: 2px; flex: none; }
.overdue-alert-survey { background: #FFF7ED; border-color: #FDBA74; color: #9A3412; }
.overdue-alert-report { background: #FEF2F2; border-color: #FCA5A5; color: #991B1B; }
.overdue-alert-date { opacity: .8; white-space: nowrap; }
@media (min-width: 992px) { .overdue-alert { margin: 16px 24px 0; } }
</style>
<?php endif; ?>
