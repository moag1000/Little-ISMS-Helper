<?php

namespace App\Service;

use App\Entity\ComplianceMapping;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Lädt YAML-Library-Files aus fixtures/library/mappings/*.yaml in die DB.
 * Validiert vor Import via MappingValidatorService und berechnet MQS-Score
 * nach erfolgreicher Persistierung.
 */
class MappingLibraryLoader
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MappingValidatorService $validator,
        private readonly MappingQualityScoreService $mqsService,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Lädt eine YAML-Datei und persistiert die Mappings.
     *
     * @return array{success: bool, errors: list<string>, warnings: list<string>, imported: int, updated: int, skipped: int}
     */
    public function load(string $yamlPath): array
    {
        $absPath = str_starts_with($yamlPath, '/') ? $yamlPath : $this->projectDir . '/' . $yamlPath;
        if (!is_readable($absPath)) {
            return $this->result(false, ["File not readable: {$absPath}"]);
        }

        try {
            $payload = Yaml::parseFile($absPath);
        } catch (\Throwable $e) {
            return $this->result(false, ["YAML parse error: " . $e->getMessage()]);
        }

        $validation = $this->validator->validate($payload);
        if (!empty($validation['errors'])) {
            return [
                'success' => false,
                'errors' => $validation['errors'],
                'warnings' => $validation['warnings'],
                'imported' => 0, 'updated' => 0, 'skipped' => 0,
            ];
        }

        $library = $payload['library'];
        $entries = $payload['mappings'] ?? [];

        $sourceFw = $this->frameworkRepository->findOneBy(['code' => $library['source_framework']])
            ?? $this->frameworkRepository->findOneBy(['name' => $library['source_framework']]);
        $targetFw = $this->frameworkRepository->findOneBy(['code' => $library['target_framework']])
            ?? $this->frameworkRepository->findOneBy(['name' => $library['target_framework']]);

        $libraryProvenanceSource = $library['provenance']['primary_source'] ?? null;
        $libraryProvenanceUrl = $library['provenance']['primary_source_url'] ?? null;
        $libraryMethodologyType = $library['methodology']['type'] ?? null;
        $libraryMethodologyDesc = $library['methodology']['description'] ?? null;
        $libraryLifecycle = $library['lifecycle']['state'] ?? 'draft';
        $libraryVersion = (int) ($library['version'] ?? 1);
        $libraryEffectiveFrom = !empty($library['effective_from'])
            ? new \DateTimeImmutable($library['effective_from'])
            : new \DateTimeImmutable();
        $libraryEffectiveUntil = !empty($library['effective_until'])
            ? new \DateTimeImmutable($library['effective_until'])
            : null;
        $sourceTag = $library['id'] ?? sprintf('%s_to_%s_v%s', $library['source_framework'], $library['target_framework'], $library['version']);

        $imported = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            $sourceReq = $this->requirementRepository->findOneBy([
                'complianceFramework' => $sourceFw,
                'requirementId' => $entry['source'],
            ]);
            $targetReq = $this->requirementRepository->findOneBy([
                'complianceFramework' => $targetFw,
                'requirementId' => $entry['target'],
            ]);
            if ($sourceReq === null || $targetReq === null) {
                $skipped++;
                continue;
            }

            // Existierendes Mapping mit gleichem source/target/source-tag finden
            $existing = $this->mappingRepository->findOneBy([
                'sourceRequirement' => $sourceReq,
                'targetRequirement' => $targetReq,
                'source' => $sourceTag,
            ]);
            $mapping = $existing ?? new ComplianceMapping();
            $mapping->setSourceRequirement($sourceReq);
            $mapping->setTargetRequirement($targetReq);
            $mapping->setSource($sourceTag);
            $mapping->setVersion($libraryVersion);
            $mapping->setValidFrom($libraryEffectiveFrom);
            $mapping->setValidUntil($libraryEffectiveUntil);
            $mapping->setLifecycleState($libraryLifecycle);
            $mapping->setProvenanceSource($libraryProvenanceSource);
            $mapping->setProvenanceUrl($libraryProvenanceUrl);
            $mapping->setMethodologyType($libraryMethodologyType);
            $mapping->setMethodologyDescription($libraryMethodologyDesc);

            if (isset($entry['relationship'])) {
                $mapping->setRelationship($entry['relationship']);
            }
            if (isset($entry['confidence'])) {
                $mapping->setConfidence($entry['confidence']);
            }
            if (isset($entry['rationale'])) {
                $mapping->setMappingRationale($entry['rationale']);
            }
            if (isset($entry['gap_warning'])) {
                $mapping->setGapWarning($entry['gap_warning']);
            }
            if (isset($entry['audit_evidence_hint'])) {
                $mapping->setAuditEvidenceHint($entry['audit_evidence_hint']);
            }
            // Übersetze relationship → mappingPercentage (für Legacy-Code)
            if ($mapping->getMappingPercentage() === 0 && isset($entry['relationship'])) {
                $mapping->setMappingPercentage(match ($entry['relationship']) {
                    'equivalent' => 100,
                    'subset' => 70,         // source ist Teil von target → bei subset abgedeckt teilweise
                    'superset' => 120,
                    'partial_overlap' => 50,
                    'related' => 30,
                    default => 0,
                });
            }

            $mapping->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->persist($mapping);

            if ($existing) {
                $updated++;
            } else {
                $imported++;
            }
        }

        $this->entityManager->flush();

        // MQS-Score für jedes geladene Mapping berechnen
        foreach ($entries as $entry) {
            $sourceReq = $this->requirementRepository->findOneBy([
                'complianceFramework' => $sourceFw,
                'requirementId' => $entry['source'],
            ]);
            $targetReq = $this->requirementRepository->findOneBy([
                'complianceFramework' => $targetFw,
                'requirementId' => $entry['target'],
            ]);
            if ($sourceReq === null || $targetReq === null) {
                continue;
            }
            $mapping = $this->mappingRepository->findOneBy([
                'sourceRequirement' => $sourceReq,
                'targetRequirement' => $targetReq,
                'source' => $sourceTag,
            ]);
            if ($mapping !== null) {
                $this->mqsService->compute($mapping);
            }
        }
        $this->entityManager->flush();

        return [
            'success' => true,
            'errors' => [],
            'warnings' => $validation['warnings'],
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param list<string> $errors
     */
    private function result(bool $success, array $errors): array
    {
        return ['success' => $success, 'errors' => $errors, 'warnings' => [], 'imported' => 0, 'updated' => 0, 'skipped' => 0];
    }
}
