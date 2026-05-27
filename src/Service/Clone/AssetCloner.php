<?php

declare(strict_types=1);

namespace App\Service\Clone;

use App\Entity\Asset;
use App\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Asset Cloner (C4-C1 — Klon-Funktionen).
 *
 * Use case: onboard a fleet of similar devices ("12 production workstations"),
 * duplicate an SaaS-Asset across departments, or template an AI-Agent asset.
 * The clone keeps the configuration (type, sub-type, location, CIA values,
 * data classification, handling instructions, AI-agent metadata) and resets
 * lifecycle state.
 *
 * Reset on clone:
 *   - status → 'active' (initial lifecycle marking; see Asset entity)
 *   - returnDate cleared
 *   - currentValue reset to acquisitionValue (caller can override later)
 *   - timestamps regenerated
 *
 * Cascade omissions:
 *   - risks / incidents / protectingControls (M2M, inverse) — cloning these
 *     would re-attach the new asset to existing risk/incident rows, which
 *     would falsify the historical assessment trail
 *   - dependsOn / dependentAssets — the dependency graph is asset-instance-
 *     specific (cloned VM is not the same as the original)
 *   - processingActivities — GDPR record-of-processing references are tied
 *     to the specific asset instance
 *
 * Caller is expected to flush.
 */
final class AssetCloner implements EntityClonerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supportsEntity(): string
    {
        return Asset::class;
    }

    /**
     * @param Asset $source
     */
    public function clone(object $source, ?Tenant $targetTenant = null, ?string $titleOverride = null): Asset
    {
        if (!$source instanceof Asset) {
            throw new \InvalidArgumentException(sprintf(
                'AssetCloner expects %s, got %s',
                Asset::class,
                $source::class,
            ));
        }

        $clone = new Asset();

        $baseName = (string) $source->getName();
        $clone->setName($titleOverride !== null && $titleOverride !== ''
            ? $titleOverride
            : ($baseName !== '' ? $baseName . ' (Kopie)' : 'Kopie')
        );

        $tenant = $targetTenant ?? $source->getTenant();
        if ($tenant instanceof Tenant) {
            $clone->setTenant($tenant);
        }

        $clone->setDescription($source->getDescription());
        $clone->setAssetType($source->getAssetType());
        $clone->setSubType($source->getSubType());
        $clone->setOwner($source->getOwner());
        $clone->setPhysicalLocation($source->getPhysicalLocation());
        $clone->setLocation($source->getLocation());
        $clone->setAcquisitionValue($source->getAcquisitionValue());
        $clone->setCurrentValue($source->getAcquisitionValue()); // reset to acquisition baseline

        // BSI 3.6 CIA-triad — assessment travels with the asset template.
        $clone->setConfidentialityValue($source->getConfidentialityValue());
        $clone->setIntegrityValue($source->getIntegrityValue());
        $clone->setAvailabilityValue($source->getAvailabilityValue());

        $clone->setDataClassification($source->getDataClassification());
        $clone->setTisaxInformationClassification($source->getTisaxInformationClassification());
        $clone->setAcceptableUsePolicy($source->getAcceptableUsePolicy());
        $clone->setHandlingInstructions($source->getHandlingInstructions());
        $clone->setIsDoraRelevant($source->isDoraRelevant());

        // AI-agent metadata (EU AI Act) — full template carries over.
        if ($source->isAiAgent()) {
            $clone->setAiAgentClassification($source->getAiAgentClassification());
            $clone->setAiAgentPurpose($source->getAiAgentPurpose());
            $clone->setAiAgentDataSources($source->getAiAgentDataSources());
            $clone->setAiAgentOversightMechanism($source->getAiAgentOversightMechanism());
            $clone->setAiAgentProvider($source->getAiAgentProvider());
            $clone->setAiAgentModelVersion($source->getAiAgentModelVersion());
            $clone->setAiAgentCapabilityScope($source->getAiAgentCapabilityScope());
            $clone->setAiAgentThreatModelDocId($source->getAiAgentThreatModelDocId());
            $clone->setAiAgentExtensionAllowlist($source->getAiAgentExtensionAllowlist());
        }

        // Reset lifecycle to active; clear return-date.
        $clone->setStatus('active');
        $clone->setReturnDate(null);

        $clone->setCreatedAt(new DateTimeImmutable());
        $clone->setUpdatedAt(null);

        $this->entityManager->persist($clone);

        return $clone;
    }
}
