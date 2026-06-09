<?php

declare(strict_types=1);

namespace App\EventListener;

/**
 * ⚠  SUPERSEDED — kept as an empty shell so the class name stays importable
 *    by the test suite and any already-merged references do not cause
 *    autoloader errors.
 *
 * The full notification-rule triggering for ALL 10 event types that the UI
 * exposes (`NotificationRuleType`) is now handled by:
 *
 *   • Entity-event-driven (postPersist / postUpdate change-set):
 *       - incident.created, incident.severity_high
 *       - risk.created, risk.score_critical
 *       - data_breach.created, data_breach.severity_changed
 *       - audit.finding_created
 *       - document.approval_required  (status transition → in_review)
 *       - control.evidence_expired    (evidenceOutdated flag flip false→true)
 *     → App\EventSubscriber\EntityChangeNotificationSubscriber  (onFlush / postFlush)
 *       backed by App\Service\Notification\Event\DomainEventDetector (pure, DB-free detector)
 *       and App\Service\Notification\Event\DomainEventNotifier   (rule-eval + dispatch chokepoint)
 *
 *   • Time/state-deadline-driven (NO change-set — cron path):
 *       - control.overdue  — fired daily by `app:notify-overdue-controls`
 *         (App\Command\DispatchOverdueControlNotificationsCommand).
 *         A control becomes overdue by the passage of time, not by a save;
 *         hanging this off a Doctrine event would be wrong because there is
 *         no change-set to guard against repeated firing. The command uses a
 *         `lookback-hours` window (default 25 h) for idempotency.
 *
 * WHY the original `AsEntityListener` approach was retired:
 *   It directly wired postPersist / postUpdate on the Incident entity with
 *   hand-rolled change-set reading and rule evaluation.  When PR #901
 *   (feat(notifications): wire the domain-event → rule engine) was merged it
 *   introduced the EntityChangeNotificationSubscriber which covers all 10
 *   event types with:
 *     - Storm-avoidance by construction (transition from change-set, not every save)
 *     - Deferred dispatch (onFlush buffers → postFlush fires, so delivery
 *       persistence never happens mid-flush)
 *     - Re-entrancy guard for the nested delivery flush
 *     - A single condition-evaluating chokepoint (DomainEventNotifier) that
 *       isolates failing rules so one broken channel never blocks the others
 *   Keeping this listener active alongside the subscriber would cause DOUBLE
 *   dispatch of `incident.created` and `incident.severity_high`.
 *
 * For the public event-type string constants used in tests:
 *   @see App\Service\Notification\Event\DomainEventDetector
 * For the full dispatch flow:
 *   @see App\EventSubscriber\EntityChangeNotificationSubscriber
 * For the cron-driven control.overdue path:
 *   @see App\Command\DispatchOverdueControlNotificationsCommand
 */
final class NotificationRuleTriggerListener
{
    // Public constants are preserved for backwards-compat with any code that
    // references them by name (e.g. test data builders).
    public const string EVENT_INCIDENT_CREATED        = 'incident.created';
    public const string EVENT_INCIDENT_SEVERITY_HIGH  = 'incident.severity_high';
    public const string EVENT_RISK_CREATED            = 'risk.created';
    public const string EVENT_RISK_SCORE_CRITICAL     = 'risk.score_critical';
    public const string EVENT_DATA_BREACH_CREATED     = 'data_breach.created';
    public const string EVENT_DATA_BREACH_SEVERITY    = 'data_breach.severity_changed';
    public const string EVENT_AUDIT_FINDING_CREATED   = 'audit.finding_created';
    public const string EVENT_DOCUMENT_APPROVAL       = 'document.approval_required';
    public const string EVENT_CONTROL_EVIDENCE_EXPIRED = 'control.evidence_expired';

    /**
     * control.overdue is intentionally absent: it is a time-driven event with
     * no Doctrine change-set.  It belongs on the cron path.
     *
     * @see App\Command\DispatchOverdueControlNotificationsCommand
     */
    public const string EVENT_CONTROL_OVERDUE = 'control.overdue';
}
