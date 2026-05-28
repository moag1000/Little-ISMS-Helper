<?php

declare(strict_types=1);

namespace App\Service\DataIntegrity;

use App\Repository\AssetRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\RiskRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Detects and merges duplicate entities within the same tenant.
 *
 * Extracted from DataIntegrityService to isolate duplicate-detection concerns.
 *
 * @see \App\Service\DataIntegrityService::findDuplicateEntities()
 * @see \App\Service\DataIntegrityService::mergeDuplicates()
 */
final class DuplicateFinder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InternalAuditRepository $auditRepository,
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly DocumentRepository $documentRepository,
    ) {
    }

    /**
     * Find duplicate entities within the same tenant
     * (e.g., same audit number, same asset name)
     */
    public function findDuplicateEntities(): array
    {
        $duplicates = [];

        // Find audits with duplicate audit numbers within same tenant
        $audits = $this->auditRepository->findAll();
        $auditsByTenant = [];
        foreach ($audits as $audit) {
            if ($audit->getTenant()) {
                $key = $audit->getTenant()->getId() . '_' . $audit->getAuditNumber();
                if (!isset($auditsByTenant[$key])) {
                    $auditsByTenant[$key] = [];
                }
                $auditsByTenant[$key][] = $audit;
            }
        }
        foreach ($auditsByTenant as $key => $group) {
            if (count($group) > 1) {
                $duplicates['audits'][] = [
                    'key' => $key,
                    'count' => count($group),
                    'entities' => $group,
                    'field' => 'auditNumber',
                    'value' => $group[0]->getAuditNumber(),
                ];
            }
        }

        // Find assets with duplicate names within same tenant
        $assets = $this->assetRepository->findAll();
        $assetsByTenant = [];
        foreach ($assets as $asset) {
            if ($asset->getTenant()) {
                $key = $asset->getTenant()->getId() . '_' . strtolower((string) $asset->getName());
                if (!isset($assetsByTenant[$key])) {
                    $assetsByTenant[$key] = [];
                }
                $assetsByTenant[$key][] = $asset;
            }
        }
        foreach ($assetsByTenant as $key => $group) {
            if (count($group) > 1) {
                $duplicates['assets'][] = [
                    'key' => $key,
                    'count' => count($group),
                    'entities' => $group,
                    'field' => 'name',
                    'value' => $group[0]->getName(),
                ];
            }
        }

        // Find risks with duplicate titles within same tenant
        $risks = $this->riskRepository->findAll();
        $risksByTenant = [];
        foreach ($risks as $risk) {
            if ($risk->getTenant()) {
                $key = $risk->getTenant()->getId() . '_' . strtolower((string) $risk->getTitle());
                if (!isset($risksByTenant[$key])) {
                    $risksByTenant[$key] = [];
                }
                $risksByTenant[$key][] = $risk;
            }
        }
        foreach ($risksByTenant as $key => $group) {
            if (count($group) > 1) {
                $duplicates['risks'][] = [
                    'key' => $key,
                    'count' => count($group),
                    'entities' => $group,
                    'field' => 'title',
                    'value' => $group[0]->getTitle(),
                ];
            }
        }

        // Incident duplicates by title
        $incidentsByTenant = [];
        foreach ($this->incidentRepository->findAll() as $incident) {
            if ($incident->getTenant()) {
                $key = $incident->getTenant()->getId() . '_' . strtolower(trim($incident->getTitle()));
                $incidentsByTenant[$key][] = $incident;
            }
        }
        foreach ($incidentsByTenant as $group) {
            if (count($group) > 1) {
                $duplicates['incidents'][] = $group;
            }
        }

        // Document duplicates by original filename (Document has no getTitle())
        $docsByTenant = [];
        foreach ($this->documentRepository->findAll() as $doc) {
            $name = $doc->getOriginalFilename() ?? $doc->getFilename();
            if ($doc->getTenant() && $name !== null && $name !== '') {
                $key = $doc->getTenant()->getId() . '_' . strtolower(trim($name));
                $docsByTenant[$key][] = $doc;
            }
        }
        foreach ($docsByTenant as $group) {
            if (count($group) > 1) {
                $duplicates['documents'][] = $group;
            }
        }

        return $duplicates;
    }

    /**
     * Merge duplicate entities for a given entity type, keeping the entity
     * with the lowest ID (oldest) and removing the rest.
     *
     * Returns the number of deleted duplicate entities.
     *
     * Supported entity types: audits, assets, risks, incidents, documents
     */
    public function mergeDuplicates(string $entityType): int
    {
        $duplicates = $this->findDuplicateEntities();

        if (!isset($duplicates[$entityType]) || count($duplicates[$entityType]) === 0) {
            return 0;
        }

        $deleted = 0;

        foreach ($duplicates[$entityType] as $group) {
            // Normalise: groups for audits/assets/risks have an 'entities' key;
            // incidents/documents groups are plain entity arrays.
            $entities = is_array($group) && isset($group['entities'])
                ? $group['entities']
                : (array) $group;

            if (count($entities) < 2) {
                continue;
            }

            // Sort ascending by ID so the oldest survives
            usort($entities, fn($a, $b) => ($a->getId() ?? 0) <=> ($b->getId() ?? 0));

            // Keep the first (lowest ID), delete the rest
            $toDelete = array_slice($entities, 1);
            foreach ($toDelete as $entity) {
                $this->entityManager->remove($entity);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        return $deleted;
    }
}
