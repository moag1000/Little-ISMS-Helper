<?php

namespace App\Service;

use App\Entity\Asset;
use App\Entity\BusinessProcess;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Repository\BusinessProcessRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;

/**
 * Service für intelligente Schutzbedarf-Analyse mit Datenwiederverwendung
 *
 * Dieser Service nutzt Daten aus verschiedenen ISMS-Bereichen zur
 * automatischen Bewertung und Vorschlägen für Schutzbedarfe.
 */
class ProtectionRequirementService
{
    public function __construct(
        private BusinessProcessRepository $businessProcessRepository,
        private IncidentRepository $incidentRepository,
        private RiskRepository $riskRepository
    ) {}

    /**
     * Berechnet den Schutzbedarf Verfügbarkeit basierend auf BCM-Daten
     *
     * Nutzt: Business Process BIA (RTO, MTPD, Impact Scores)
     */
    public function calculateAvailabilityRequirement(Asset $asset): array
    {
        $processes = $this->businessProcessRepository->createQueryBuilder('bp')
            ->where(':asset MEMBER OF bp.supportingAssets')
            ->setParameter('asset', $asset)
            ->getQuery()
            ->getResult();

        if (empty($processes)) {
            return [
                'value' => $asset->getAvailabilityValue(),
                'source' => 'manual',
                'confidence' => 'low',
                'recommendation' => null,
                'reasoning' => 'Keine BCM-Daten verfügbar'
            ];
        }

        // Finde kritischsten Prozess
        $mostCritical = null;
        $lowestRto = PHP_INT_MAX;

        foreach ($processes as $process) {
            if ($process->getRto() < $lowestRto) {
                $lowestRto = $process->getRto();
                $mostCritical = $process;
            }
        }

        $suggestedValue = $mostCritical->getSuggestedAvailabilityValue();
        $currentValue = $asset->getAvailabilityValue();

        $reasoning = sprintf(
            'Basierend auf Geschäftsprozess "%s" mit RTO=%dh, MTPD=%dh. Business Impact: %d/5',
            $mostCritical->getName(),
            $mostCritical->getRto(),
            $mostCritical->getMtpd(),
            $mostCritical->getBusinessImpactScore()
        );

        return [
            'value' => $suggestedValue,
            'current' => $currentValue,
            'source' => 'bcm',
            'confidence' => 'high',
            'recommendation' => $currentValue !== $suggestedValue ? $suggestedValue : null,
            'reasoning' => $reasoning,
            'process' => $mostCritical
        ];
    }

    /**
     * Berechnet Schutzbedarf Vertraulichkeit basierend auf Incidents und Risks
     *
     * Nutzt: Historische Vorfälle, identifizierte Risiken
     */
    public function calculateConfidentialityRequirement(Asset $asset): array
    {
        // Prüfe ob Asset in Datenschutzverletzungen involviert war
        $incidents = $this->incidentRepository->createQueryBuilder('i')
            ->where('i.dataBreachOccurred = true')
            ->andWhere('i.description LIKE :assetName OR i.description LIKE :assetId')
            ->setParameter('assetName', '%' . $asset->getName() . '%')
            ->setParameter('assetId', '%' . $asset->getId() . '%')
            ->getQuery()
            ->getResult();

        // Prüfe Risiken mit hoher Vertraulichkeits-Bedrohung
        $risks = $this->riskRepository->createQueryBuilder('r')
            ->where('r.asset = :asset')
            ->andWhere('r.threat LIKE :confidentiality')
            ->setParameter('asset', $asset)
            ->setParameter('confidentiality', '%vertraulich%')
            ->getQuery()
            ->getResult();

        $currentValue = $asset->getConfidentialityValue();
        $suggestedValue = $currentValue;
        $reasoning = 'Basierend auf aktueller Bewertung';

        if (count($incidents) > 0) {
            $suggestedValue = max($suggestedValue, 4);
            $reasoning = sprintf(
                '%d Datenschutzverletzung(en) mit diesem Asset. Vertraulichkeit sollte hoch sein.',
                count($incidents)
            );
        }

        if (count($risks) > 2) {
            $suggestedValue = max($suggestedValue, 4);
            $reasoning .= sprintf(
                ' %d identifizierte Vertraulichkeitsrisiken.',
                count($risks)
            );
        }

        return [
            'value' => $suggestedValue,
            'current' => $currentValue,
            'source' => 'incidents_risks',
            'confidence' => count($incidents) > 0 || count($risks) > 0 ? 'high' : 'medium',
            'recommendation' => $currentValue !== $suggestedValue ? $suggestedValue : null,
            'reasoning' => $reasoning,
            'incidents' => count($incidents),
            'risks' => count($risks)
        ];
    }

    /**
     * Berechnet Schutzbedarf Integrität basierend auf Incidents
     *
     * Nutzt: Vorfälle mit Datenmanipulation
     */
    public function calculateIntegrityRequirement(Asset $asset): array
    {
        $incidents = $this->incidentRepository->createQueryBuilder('i')
            ->where('i.category = :category')
            ->andWhere('i.description LIKE :assetName')
            ->setParameter('category', 'data_integrity')
            ->setParameter('assetName', '%' . $asset->getName() . '%')
            ->getQuery()
            ->getResult();

        $currentValue = $asset->getIntegrityValue();
        $suggestedValue = $currentValue;

        if (count($incidents) > 0) {
            $suggestedValue = max($currentValue, 4);
            $reasoning = sprintf(
                '%d Integritätsverletzung(en) dokumentiert. Integrität sollte hoch bewertet werden.',
                count($incidents)
            );
        } else {
            $reasoning = 'Keine Integritätsvorfälle dokumentiert';
        }

        return [
            'value' => $suggestedValue,
            'current' => $currentValue,
            'source' => 'incidents',
            'confidence' => count($incidents) > 0 ? 'high' : 'low',
            'recommendation' => $currentValue !== $suggestedValue ? $suggestedValue : null,
            'reasoning' => $reasoning,
            'incidents' => count($incidents)
        ];
    }

    /**
     * Vollständige Schutzbedarfsanalyse mit allen Datenquellen
     */
    public function getCompleteProtectionRequirementAnalysis(Asset $asset): array
    {
        return [
            'asset' => $asset,
            'confidentiality' => $this->calculateConfidentialityRequirement($asset),
            'integrity' => $this->calculateIntegrityRequirement($asset),
            'availability' => $this->calculateAvailabilityRequirement($asset),
            'timestamp' => new \DateTime()
        ];
    }

    /**
     * Identifiziert Assets, die eine Neubewertung benötigen
     */
    public function getAssetsRequiringReview(): array
    {
        // Diese Logik kann erweitert werden
        return [];
    }
}
