<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Incident;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Junior-ISB-Audit-2026-05-22 C2-01 — Doppelpflege-Deprecation listener.
 *
 * Emits a developer-facing deprecation warning when a brand-new Incident
 * is persisted with the legacy `affectedSystems` freetext field populated
 * but the structured `affectedAssets` collection still empty. The pair
 * represents the same concept (ISO 27001 A.5.26 + DORA Art. 17 demand
 * structured incident-asset linkage); the freetext field is retained as
 * read-only Legacy column until the S14 cleanup migration drops it.
 *
 * Production: silent (NullLogger fallback) — never blocks persist, never
 * raises. Dev/test: writes to monolog deprecation channel so the audit
 * trail catches fixture-seeders or import paths that still populate the
 * freetext.
 *
 * Behaviour matrix (prePersist):
 *   - Both fields empty           → no-op
 *   - affectedAssets populated    → no-op (structured-only is correct)
 *   - affectedSystems populated +
 *     affectedAssets empty        → deprecation warning logged
 *   - Both populated              → no-op (assumed migration in progress)
 */
#[AsEntityListener(event: Events::prePersist, entity: Incident::class)]
final class IncidentAffectedSystemsDeprecationListener
{
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function prePersist(Incident $incident, PrePersistEventArgs $args): void
    {
        $freetext = $incident->getAffectedSystems();
        if ($freetext === null || trim($freetext) === '') {
            return;
        }

        // Structured linkage already in place — caller is migrating, stay silent.
        if (\count($incident->getAffectedAssets()) > 0) {
            return;
        }

        $this->logger->warning(
            'incident.affectedSystems freetext populated without structured affectedAssets — '
            . 'this field is @deprecated since S13 (2026-05-23) per Junior-ISB-Audit C2-01. '
            . 'Use Incident::addAffectedAsset() to link Asset entities instead '
            . '(ISO 27001 A.5.26 + DORA Art. 17).',
            [
                'incident_number' => $incident->getIncidentNumber(),
                'freetext_length' => \strlen($freetext),
                'category'        => 'doppelpflege.deprecation',
                'audit_ref'       => 'junior-isb-audit-2026-05-22-C2-01',
            ],
        );
    }
}
