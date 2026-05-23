<?php

declare(strict_types=1);

namespace App\Listener;

use App\Entity\CorrectiveAction;
use App\Entity\Incident;
use App\Enum\CorrectiveActionStatus;
use App\Enum\IncidentSeverity;
use App\Repository\SystemSettingsRepository;
use App\Service\AutoReactionService;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Junior-ISB-Audit-2026-05-22 C2-05 — CAPA-Canonical-Process: Incident path.
 *
 * Mirrors {@see \App\EventListener\AutoReactionCorrectiveActionListener} (the
 * AuditFinding-path, in production since H-01). ISO 27001 Cl. 10.1
 * closure-loop: every high/critical Incident with a documented root-cause
 * gets a structured CorrectiveAction skeleton so that due-date, owner, and
 * effectiveness-review can be tracked — no longer just freetext in
 * `Incident.correctiveActions`.
 *
 * Trigger conditions (per ADR 2026-05-23):
 *   - Incident.severity in {high, critical}
 *   - Incident.rootCause is non-empty (the analyst documented the cause)
 *   - No CorrectiveAction with sourceIncident=this exists yet (idempotent)
 *
 * Behaviour:
 *   - Create CorrectiveAction skeleton: sourceType='incident', sourceIncident=this,
 *     rootCauseAnalysis=Incident.rootCause, description=Incident.correctiveActions,
 *     actionType='corrective', status='planned', planned completion = +N days
 *     (resolved via SystemSettings auto_reactions.auto_ca_due_days, severity-aware).
 *
 * Feature flag: {@see AutoReactionService::KEY_CA_ON_INCIDENT} (default ON).
 *
 * @see docs/decisions/2026-05-23-capa-canonical-process.md
 * @see \App\EventListener\AutoReactionCorrectiveActionListener — reference pattern
 */
#[AsEntityListener(event: Events::postPersist, entity: Incident::class)]
#[AsEntityListener(event: Events::postUpdate, entity: Incident::class)]
final class AutoReactionCorrectiveActionListenerForIncident
{
    public const SETTINGS_CATEGORY = 'auto_reactions';
    public const SETTINGS_KEY_CA_DUE_DAYS = 'auto_ca_due_days';

    /** @var array<string, int> */
    private const DEFAULT_DUE_DAYS = [
        'critical' => 14,
        'high'     => 30,
        'medium'   => 60,
        'low'      => 90,
    ];

    public function __construct(
        private readonly AutoReactionService $reactions,
        private readonly LoggerInterface $logger,
        private readonly ?SystemSettingsRepository $systemSettings = null,
    ) {
    }

    public function postPersist(Incident $incident, PostPersistEventArgs $args): void
    {
        $this->maybeCreateCa($incident, $args);
    }

    public function postUpdate(Incident $incident, PostUpdateEventArgs $args): void
    {
        $this->maybeCreateCa($incident, $args);
    }

    private function maybeCreateCa(Incident $incident, mixed $args): void
    {
        if (!$this->reactions->isEnabled(AutoReactionService::KEY_CA_ON_INCIDENT)) {
            return;
        }

        $severity = $incident->getSeverity();
        if (!in_array($severity, [IncidentSeverity::High, IncidentSeverity::Critical], true)) {
            return;
        }

        $rootCause = $incident->getRootCause();
        if ($rootCause === null || trim($rootCause) === '') {
            return;
        }

        try {
            $em = $args->getObjectManager();

            // Idempotency — has a CA already been materialised for this Incident?
            $existing = $em->getRepository(CorrectiveAction::class)->findOneBy([
                'sourceIncident' => $incident,
            ]);
            if ($existing instanceof CorrectiveAction) {
                return;
            }

            $ca = new CorrectiveAction();
            $ca->setTenant($incident->getTenant());
            $ca->setSourceIncident($incident);
            $ca->setSourceType(CorrectiveAction::SOURCE_TYPE_INCIDENT);
            $ca->setTitle(sprintf('CAPA für Incident: %s', (string) ($incident->getTitle() ?? '—')));
            $ca->setDescription(
                (string) ($incident->getCorrectiveActions() ?? 'Auto-generierte CAPA-Skizze. Maßnahmen, Verantwortlichen und Zieldatum nachpflegen.')
            );
            $ca->setRootCauseAnalysis($rootCause);
            $ca->setActionType(CorrectiveAction::ACTION_TYPE_CORRECTIVE);
            $ca->setPlannedCompletionDate(
                new DateTimeImmutable('+' . $this->resolveDueDays($severity?->value) . ' days')
            );
            $ca->setStatus(CorrectiveActionStatus::Planned); // @phpstan-ignore lifecycle.directSetStatus (auto-reaction listener — initial state on new pre-persist entity)

            $em->persist($ca);
            $em->flush();

            $this->logger->info('Auto-CorrectiveAction created for incident', [
                'incident_id' => $incident->getId(),
                'incident_severity' => $severity?->value,
                'ca_id' => $ca->getId(),
                'source_type' => CorrectiveAction::SOURCE_TYPE_INCIDENT,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Auto-CorrectiveAction for Incident failed', [
                'incident_id' => $incident->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map severity to days-until-due. Resolution order:
     *   1. SystemSettings `auto_reactions.auto_ca_due_days` JSON dict (per-tenant configurable).
     *   2. DEFAULT_DUE_DAYS fallback.
     *   3. 30 days hard fallback.
     */
    private function resolveDueDays(?string $severity): int
    {
        $config = self::DEFAULT_DUE_DAYS;
        if ($this->systemSettings !== null) {
            $stored = $this->systemSettings->getSetting(
                self::SETTINGS_CATEGORY,
                self::SETTINGS_KEY_CA_DUE_DAYS,
                null,
            );
            if (is_array($stored)) {
                foreach ($stored as $k => $v) {
                    $key = strtolower((string) $k);
                    $val = (int) $v;
                    if ($val > 0 && $val <= 365) {
                        $config[$key] = $val;
                    }
                }
            }
        }

        $sev = strtolower((string) $severity);
        if ($sev !== '' && isset($config[$sev])) {
            return $config[$sev];
        }
        return 30;
    }
}
