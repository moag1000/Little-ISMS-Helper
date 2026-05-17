<?php

declare(strict_types=1);

namespace App\Service\Library;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Imports BSI IT-Grundschutz Kompendium YAML fixtures into the DB.
 *
 * Parses fixtures/library/frameworks/bsi-it-grundschutz-2024.yaml (or any
 * structurally compatible BSI YAML) and persists ComplianceFramework +
 * ComplianceRequirement rows via Doctrine. Idempotent: existing rows are
 * updated in-place using (code, requirementId) as natural key.
 *
 * Not final so it can be mocked in tests.
 */
final class BsiKompendiumImporter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Import from the default BSI 2024 fixture path.
     *
     * @return array{frameworks_created: int, frameworks_updated: int, requirements_created: int, requirements_updated: int, errors: list<string>}
     */
    public function importDefault(): array
    {
        $path = $this->projectDir . '/fixtures/library/frameworks/bsi-it-grundschutz-2024.yaml';

        return $this->importYaml($path);
    }

    /**
     * Import from an arbitrary YAML path.
     *
     * @return array{frameworks_created: int, frameworks_updated: int, requirements_created: int, requirements_updated: int, errors: list<string>}
     */
    public function importYaml(string $yamlPath): array
    {
        $stats = [
            'frameworks_created' => 0,
            'frameworks_updated' => 0,
            'requirements_created' => 0,
            'requirements_updated' => 0,
            'errors' => [],
        ];

        if (!file_exists($yamlPath)) {
            $stats['errors'][] = sprintf('YAML file not found: %s', $yamlPath);
            return $stats;
        }

        /** @var array<string, mixed> $data */
        $data = Yaml::parseFile($yamlPath);

        if (!isset($data['metadata']) || !is_array($data['metadata'])) {
            $stats['errors'][] = 'Missing or invalid "metadata" key in YAML.';
            return $stats;
        }

        $meta = $data['metadata'];
        $framework = $this->upsertFramework($meta, $stats);

        if (!isset($data['bausteine']) || !is_array($data['bausteine'])) {
            return $stats;
        }

        foreach ($data['bausteine'] as $baustein) {
            if (!is_array($baustein)) {
                continue;
            }
            $this->processBaustein($framework, $baustein, $stats);
        }

        $this->entityManager->flush();

        return $stats;
    }

    /**
     * @param array<string, mixed> $meta
     * @param array{frameworks_created: int, frameworks_updated: int, requirements_created: int, requirements_updated: int, errors: list<string>} $stats
     */
    private function upsertFramework(array $meta, array &$stats): ComplianceFramework
    {
        $code = (string) ($meta['code'] ?? 'BSI-GRUNDSCHUTZ-2024');
        $framework = $this->frameworkRepository->findOneBy(['code' => $code]);

        if ($framework === null) {
            $framework = new ComplianceFramework();
            $framework->setCode($code);
            $this->entityManager->persist($framework);
            $stats['frameworks_created']++;
        } else {
            $stats['frameworks_updated']++;
        }

        $framework->setName((string) ($meta['name'] ?? 'BSI IT-Grundschutz-Kompendium 2024'));
        $framework->setVersion((string) ($meta['version'] ?? '2024.1'));
        $framework->setDescription((string) ($meta['note'] ?? $meta['name'] ?? ''));
        $framework->setApplicableIndustry('all');
        $framework->setRegulatoryBody((string) ($meta['body'] ?? 'BSI'));
        $framework->setMandatory(false);
        $framework->setActive(true);
        $framework->setLifecycleState(ComplianceFramework::LIFECYCLE_ACTIVE);

        return $framework;
    }

    /**
     * @param array<string, mixed> $baustein
     * @param array{frameworks_created: int, frameworks_updated: int, requirements_created: int, requirements_updated: int, errors: list<string>} $stats
     */
    private function processBaustein(ComplianceFramework $framework, array $baustein, array &$stats): void
    {
        $bausteinId = (string) ($baustein['id'] ?? '');
        $schicht = (string) ($baustein['schicht'] ?? '');

        if ($bausteinId === '') {
            $stats['errors'][] = 'Baustein without id — skipped.';
            return;
        }

        // Create a parent requirement for the Baustein itself
        $bausteinReqId = $bausteinId;
        $parentReq = $this->requirementRepository->findOneBy([
            'framework' => $framework,
            'requirementId' => $bausteinReqId,
        ]);

        if ($parentReq === null) {
            $parentReq = new ComplianceRequirement();
            $parentReq->setFramework($framework);
            $parentReq->setRequirementId($bausteinReqId);
            $this->entityManager->persist($parentReq);
            $stats['requirements_created']++;
        } else {
            $stats['requirements_updated']++;
        }

        $parentReq->setTitle((string) ($baustein['name'] ?? $bausteinId));
        $parentReq->setDescription((string) ($baustein['description'] ?? ''));
        $parentReq->setCategory($schicht ?: null);
        $parentReq->setPriority('medium');
        $parentReq->setRequirementType('core');

        // Process individual Anforderungen
        if (!isset($baustein['anforderungen']) || !is_array($baustein['anforderungen'])) {
            return;
        }

        foreach ($baustein['anforderungen'] as $anf) {
            if (!is_array($anf)) {
                continue;
            }
            $this->processAnforderung($framework, $parentReq, $anf, $stats);
        }
    }

    /**
     * @param array<string, mixed> $anf
     * @param array{frameworks_created: int, frameworks_updated: int, requirements_created: int, requirements_updated: int, errors: list<string>} $stats
     */
    private function processAnforderung(
        ComplianceFramework $framework,
        ComplianceRequirement $parent,
        array $anf,
        array &$stats,
    ): void {
        $reqId = (string) ($anf['id'] ?? '');
        if ($reqId === '') {
            return;
        }

        $req = $this->requirementRepository->findOneBy([
            'framework' => $framework,
            'requirementId' => $reqId,
        ]);

        if ($req === null) {
            $req = new ComplianceRequirement();
            $req->setFramework($framework);
            $req->setRequirementId($reqId);
            $req->setParentRequirement($parent);
            $this->entityManager->persist($req);
            $stats['requirements_created']++;
        } else {
            $stats['requirements_updated']++;
        }

        $req->setTitle((string) ($anf['title'] ?? $reqId));
        $req->setDescription((string) ($anf['text'] ?? ''));
        $req->setCategory($parent->getCategory());
        $req->setRequirementType('detailed');

        $level = (string) ($anf['level'] ?? 'basis');
        $req->setPriority(match ($level) {
            'erhoeht' => 'high',
            'standard' => 'medium',
            default => 'low',
        });
    }
}
