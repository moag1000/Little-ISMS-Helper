<?php

declare(strict_types=1);

namespace App\Listener;

/**
 * Junior-ISB-Audit-2026-05-22 M-07: CAPA-Canonical-Process consolidation —
 * STUB for the Incident-path of the auto-CorrectiveAction listener pattern.
 *
 * TODO (sprint S14): Implement as Doctrine entity-listener mirroring
 * {@see \App\EventListener\AutoReactionCorrectiveActionListener} (the
 * AuditFinding-path that has been in production since H-01).
 *
 * Trigger conditions (per ADR):
 *   - Incident.severity in {high, critical}
 *   - Incident.rootCause is non-empty
 *
 * Behaviour:
 *   - Materialise a structured CorrectiveAction row with sourceType=incident,
 *     sourceIncident=this, rootCauseAnalysis=Incident.rootCause,
 *     description=Incident.correctiveActions, actionType=corrective,
 *     status=planned, planned completion = +30 days (configurable via
 *     SystemSettings.auto_reactions.auto_ca_due_days, already in production
 *     for the AuditFinding path).
 *
 * Feature flag: AutoReactionService::KEY_CA_ON_INCIDENT (to be added in S14,
 * default OFF until validation complete).
 *
 * Mounting (in S14 — NOT YET):
 *   #[AsEntityListener(event: Events::postPersist, entity: Incident::class)]
 *   #[AsEntityListener(event: Events::postUpdate, entity: Incident::class)]
 *
 * This stub class is intentionally inert. It exists so that S14 implementation
 * can be search-discovered and tracked, and so that file-system layout matches
 * the ADR before the implementation lands.
 *
 * @see docs/decisions/2026-05-23-capa-canonical-process.md
 * @see \App\EventListener\AutoReactionCorrectiveActionListener — reference pattern
 * @see \App\Command\MigrateCapaToCanonicalCommand — companion scope-audit command
 */
final class AutoReactionCorrectiveActionListenerForIncident
{
    // TODO (S14): inject AutoReactionService, LoggerInterface,
    // EmailNotificationService, UserRepository, SystemSettingsRepository.

    // TODO (S14): implement postPersist(Incident $incident, PostPersistEventArgs $args): void
    // TODO (S14): implement postUpdate(Incident $incident, PostUpdateEventArgs $args): void
    // TODO (S14): implement private maybeCreateCa(Incident $incident, mixed $args): void
    // TODO (S14): implement private resolveDueDays(?string $severity): int
    // TODO (S14): implement private notifyAssignee(Incident $incident, CorrectiveAction $ca): void
}
