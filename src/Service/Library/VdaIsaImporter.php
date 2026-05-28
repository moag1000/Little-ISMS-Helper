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
 * Imports VDA ISA TISAX YAML fixtures into the DB.
 *
 * Parses fixtures/library/frameworks/vda-isa-tisax-v6.yaml (or any
 * structurally compatible TISAX YAML) and persists ComplianceFramework +
 * ComplianceRequirement rows via Doctrine. Idempotent: existing rows are
 * updated in-place using (code, requirementId) as natural key.
 *
 * Not final so it can be mocked in tests.
 */
final class VdaIsaImporter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly string $projectDir,
    ) {
    }

    /**
     * Import from the default TISAX v6 fixture path.
     *
     * @return array{frameworks_created: int, frameworks_updated: int, requirements_created: int, requirements_updated: int, errors: list<string>}
     */
    public function importDefault(): array
    {
        $path = $this->projectDir . '/fixtures/library/frameworks/vda-isa-tisax-v6.yaml';

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

        if (!isset($data['kontrollen']) || !is_array($data['kontrollen'])) {
            return $stats;
        }

        // Build chapter lookup for parent requirements
        $chapterParents = [];
        if (isset($data['kapitel']) && is_array($data['kapitel'])) {
            foreach ($data['kapitel'] as $kap) {
                if (is_array($kap) && isset($kap['id'], $kap['title'])) {
                    $chapterParents[$kap['id']] = $this->upsertChapterParent(
                        $framework,
                        (string) $kap['id'],
                        (string) $kap['title'],
                        $stats,
                    );
                }
            }
        }

        foreach ($data['kontrollen'] as $kontrolle) {
            if (!is_array($kontrolle)) {
                continue;
            }
            $this->processKontrolle($framework, $kontrolle, $chapterParents, $stats);
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
        $code = (string) ($meta['code'] ?? 'TISAX-VDA-ISA-6');
        $framework = $this->frameworkRepository->findOneBy(['code' => $code]);

        if ($framework === null) {
            $framework = new ComplianceFramework();
            $framework->setCode($code);
            $this->entityManager->persist($framework);
            $stats['frameworks_created']++;
        } else {
            $stats['frameworks_updated']++;
        }

        $framework->setName((string) ($meta['name'] ?? 'TISAX VDA ISA v6.0'));
        $framework->setVersion((string) ($meta['version'] ?? '6.0'));
        $framework->setDescription((string) ($meta['note'] ?? $meta['name'] ?? ''));
        $framework->setApplicableIndustry('automotive');
        $framework->setRegulatoryBody((string) ($meta['body'] ?? 'VDA / ENX Association'));
        $framework->setMandatory(false);
        $framework->setActive(true);
        $framework->setLifecycleState(ComplianceFramework::LIFECYCLE_ACTIVE);

        return $framework;
    }

    /**
     * @param array{frameworks_created: int, frameworks_updated: int, requirements_created: int, requirements_updated: int, errors: list<string>} $stats
     */
    private function upsertChapterParent(
        ComplianceFramework $framework,
        string $chapterId,
        string $chapterTitle,
        array &$stats,
    ): ComplianceRequirement {
        $reqId = 'ISA-KAP-' . $chapterId;
        $req = $this->requirementRepository->findOneBy([
            'framework' => $framework,
            'requirementId' => $reqId,
        ]);

        if ($req === null) {
            $req = new ComplianceRequirement();
            $req->setFramework($framework);
            $req->setRequirementId($reqId);
            $this->entityManager->persist($req);
            $stats['requirements_created']++;
        } else {
            $stats['requirements_updated']++;
        }

        $req->setTitle($chapterTitle);
        $req->setDescription('');
        $req->setCategory('CHAPTER');
        $req->setPriority('medium');
        $req->setRequirementType('core');

        return $req;
    }

    /**
     * @param array<string, mixed> $kontrolle
     * @param array<string, ComplianceRequirement> $chapterParents
     * @param array{frameworks_created: int, frameworks_updated: int, requirements_created: int, requirements_updated: int, errors: list<string>} $stats
     */
    private function processKontrolle(
        ComplianceFramework $framework,
        array $kontrolle,
        array $chapterParents,
        array &$stats,
    ): void {
        $kontrolleId = (string) ($kontrolle['id'] ?? '');
        if ($kontrolleId === '') {
            $stats['errors'][] = 'Kontrolle without id — skipped.';
            return;
        }

        $req = $this->requirementRepository->findOneBy([
            'framework' => $framework,
            'requirementId' => $kontrolleId,
        ]);

        if ($req === null) {
            $req = new ComplianceRequirement();
            $req->setFramework($framework);
            $req->setRequirementId($kontrolleId);
            $this->entityManager->persist($req);
            $stats['requirements_created']++;
        } else {
            $stats['requirements_updated']++;
        }

        $kapitel = (string) ($kontrolle['kapitel'] ?? '');
        if (isset($chapterParents[$kapitel])) {
            $req->setParentRequirement($chapterParents[$kapitel]);
        }

        $req->setTitle((string) ($kontrolle['title'] ?? $kontrolleId));
        $req->setDescription((string) ($kontrolle['description'] ?? ''));
        $req->setCategory($kapitel ?: null);
        $req->setRequirementType('detailed');

        // Map minReifegradFuerBasis to priority
        $minReifegrad = (int) ($kontrolle['minReifegradFuerBasis'] ?? 3);
        $req->setPriority(match (true) {
            $minReifegrad >= 4 => 'critical',
            $minReifegrad === 3 => 'high',
            $minReifegrad === 2 => 'medium',
            default => 'low',
        });

        // Store Prueffragen in description extension if present
        if (isset($kontrolle['prueffragen']) && is_array($kontrolle['prueffragen'])) {
            $existing = $req->getDescription();
            $prueffragen = implode("\n", array_map(
                static fn (string $q): string => '- ' . $q,
                $kontrolle['prueffragen'],
            ));
            $req->setDescription($existing . "\n\nPrueffragen:\n" . $prueffragen);
        }
    }
}
