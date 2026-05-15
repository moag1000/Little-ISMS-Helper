<?php

declare(strict_types=1);

namespace App\Service\Risk;

use App\Entity\Incident;
use App\Entity\Risk;
use App\Entity\RiskIncidentLink;
use App\Entity\User;
use App\Enum\IncidentStatus;
use App\Repository\RiskIncidentLinkRepository;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * RiskIncidentLinkService — Sprint 9B / F16
 *
 * Manages structured cross-links between Risk register entries and Incident
 * reports. Provides idempotent link/unlink and a helper for suggesting risk
 * reviews when an incident is closed.
 */
class RiskIncidentLinkService
{
    public const array VALID_LINK_TYPES = [
        'materialized',
        'suspected',
        'related',
        'mitigation_failed',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RiskIncidentLinkRepository $linkRepository,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Create a cross-link between a Risk and an Incident.
     *
     * Idempotent: if the pair already exists, the existing link is returned
     * without modification (the linkType and notes of the first link win).
     */
    public function link(
        Risk $risk,
        Incident $incident,
        string $linkType,
        ?User $linkedBy,
        ?string $notes,
    ): RiskIncidentLink {
        if (!in_array($linkType, self::VALID_LINK_TYPES, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid linkType "%s". Valid: %s', $linkType, implode(', ', self::VALID_LINK_TYPES))
            );
        }

        $existing = $this->linkRepository->findOneByRiskAndIncident($risk, $incident);
        if ($existing !== null) {
            return $existing;
        }

        $link = new RiskIncidentLink();
        $link->setTenant($risk->getTenant());
        $link->setRisk($risk);
        $link->setIncident($incident);
        $link->setLinkType($linkType);
        $link->setLinkedBy($linkedBy);
        $link->setNotes($notes);
        $link->setLinkedAt(new DateTimeImmutable());

        $this->entityManager->persist($link);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            AuditLogger::ACTION_RISK_INCIDENT_LINKED,
            'RiskIncidentLink',
            $link->getId(),
            null,
            [
                'riskId'     => $risk->getId(),
                'incidentId' => $incident->getId(),
                'linkType'   => $linkType,
            ],
            sprintf('Risk #%d linked to Incident #%d (%s)', $risk->getId() ?? 0, $incident->getId() ?? 0, $linkType),
        );

        return $link;
    }

    /**
     * Remove a cross-link between a Risk and an Incident.
     * No-op if no link exists.
     */
    public function unlink(Risk $risk, Incident $incident): void
    {
        $link = $this->linkRepository->findOneByRiskAndIncident($risk, $incident);
        if ($link === null) {
            return;
        }

        $linkId   = $link->getId();
        $riskId   = $risk->getId();
        $incidentId = $incident->getId();

        $this->entityManager->remove($link);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            AuditLogger::ACTION_RISK_INCIDENT_UNLINKED,
            'RiskIncidentLink',
            $linkId,
            ['riskId' => $riskId, 'incidentId' => $incidentId],
            null,
            sprintf('Risk #%d unlinked from Incident #%d', $riskId ?? 0, $incidentId ?? 0),
        );
    }

    /**
     * When an incident is closed, return the risks linked to it so the caller
     * can suggest a likelihood/impact review.
     *
     * Returns an empty array if the incident is not closed or has no links.
     *
     * @return Risk[]
     */
    public function suggestRiskUpdateOnIncidentClose(Incident $incident): array
    {
        if ($incident->getStatus() !== IncidentStatus::Closed) {
            return [];
        }

        $links = $this->linkRepository->findByIncident($incident);
        if (empty($links)) {
            return [];
        }

        $risks = [];
        foreach ($links as $link) {
            $risk = $link->getRisk();
            if ($risk !== null) {
                $risks[] = $risk;

                $this->auditLogger->logCustom(
                    AuditLogger::ACTION_RISK_REVIEW_SUGGESTED_FROM_INCIDENT,
                    'Risk',
                    $risk->getId(),
                    null,
                    ['incidentId' => $incident->getId()],
                    sprintf(
                        'Risk review suggested after Incident #%d closed (linked as %s)',
                        $incident->getId() ?? 0,
                        $link->getLinkType(),
                    ),
                );
            }
        }

        return $risks;
    }
}
