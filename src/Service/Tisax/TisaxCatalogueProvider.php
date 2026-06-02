<?php

declare(strict_types=1);

namespace App\Service\Tisax;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Import\Mapper\TisaxRequirementMapper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * THE single source of truth for the TISAX framework row + the canonical
 * VDA-ISA 6.0 catalogue baseline.
 *
 * Every code path that needs the TISAX framework or its catalogue MUST go
 * through this provider — the catalogue loader command, the BYO import mapper,
 * the admin library importer, dashboards/registries for display metadata. This
 * kills the prior duplication where four places each defined the framework with
 * drifting name/version (4 names, 3 versions).
 *
 * Metadata (name/version/description) comes ONLY from
 * fixtures/library/frameworks/vda-isa-tisax-v6.yaml. The catalogue is the 80
 * VDA-ISA 6.0 control NUMBERS enumerated there — numbers + structure only, no
 * ENX-licensed text (that arrives via the user's BYO workbook upload, matched
 * by control number onto tenant-scoped rows).
 *
 * Not final so it can be mocked in tests.
 */
class TisaxCatalogueProvider
{
    /** Human-readable dimension labels keyed by category code. */
    private const DIMENSION_LABEL = [
        'information_security' => 'Information Security',
        'prototype_protection' => 'Prototype Protection',
        'data_protection'      => 'Data Protection',
    ];

    /** @var array<string, mixed>|null lazily-parsed YAML cache */
    private ?array $yaml = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    /**
     * Framework metadata from the YAML — the single source for display/registry.
     *
     * @return array{code:string, name:string, version:string, description:string, body:string}
     */
    public function getMetadata(): array
    {
        $meta = $this->yaml()['metadata'] ?? [];
        return [
            'code'        => (string) ($meta['code'] ?? TisaxRequirementMapper::FRAMEWORK_CODE),
            'name'        => (string) ($meta['name'] ?? 'TISAX (VDA-ISA 6.0)'),
            'version'     => (string) ($meta['version'] ?? '6.0'),
            'description' => (string) ($meta['description'] ?? 'VDA-ISA 6.0 — automotive information security assessment.'),
            'body'        => (string) ($meta['body'] ?? 'VDA / ENX Association'),
        ];
    }

    /**
     * Find-or-create the canonical TISAX framework row, metadata from YAML.
     * Idempotent. Resolves the legacy 'TISAX-VDA-ISA-6' alias to canonical.
     * Does NOT seed requirements — use loadCatalogue() for that.
     */
    public function upsertFramework(): ComplianceFramework
    {
        $meta = $this->getMetadata();

        $framework = $this->frameworkRepository->findOneBy(['code' => $meta['code']]);
        if ($framework === null) {
            foreach (array_keys(TisaxRequirementMapper::LEGACY_CODE_ALIASES) as $legacyCode) {
                $framework = $this->frameworkRepository->findOneBy(['code' => $legacyCode]);
                if ($framework !== null) {
                    break;
                }
            }
        }

        $isNew = $framework === null;
        if ($isNew) {
            $framework = new ComplianceFramework();
            $framework->setCode($meta['code']);
        }

        $framework->setName($meta['name'])
            ->setVersion($meta['version'])
            ->setDescription($meta['description'])
            ->setApplicableIndustry('automotive')
            ->setRegulatoryBody($meta['body'])
            ->setMandatory(false)
            ->setScopeDescription('VDA-ISA 6.0 — Information Security, Prototype Protection, Data Protection')
            ->setActive(true);

        if ($isNew) {
            $this->em->persist($framework);
        }
        $this->em->flush();

        return $framework;
    }

    /**
     * Upsert the framework AND seed the canonical 80 control-number baseline as
     * SHARED rows (upload_tenant_id = NULL): numbers + dimension + section only,
     * placeholder titles, NO ENX text. Idempotent — matches by (framework,
     * controlId, uploadTenant=null).
     *
     * @return array{framework: ComplianceFramework, created:int, updated:int, skipped:int, total:int}
     */
    public function loadCatalogue(bool $update = false): array
    {
        $framework = $this->upsertFramework();
        $requirements = $this->yaml()['requirements'] ?? [];

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($requirements as $reqData) {
            $controlId = (string) $reqData['controlId'];
            $category = (string) ($reqData['category'] ?? 'information_security');
            $section = (string) ($reqData['section'] ?? '');
            $title = (string) ($reqData['title'] ?? ('VDA-ISA ' . $controlId));
            $description = sprintf(
                'VDA-ISA 6.0 %s (%s). Volltext (Kontrollfrage/Ziel/Massnahme) via lizenzierten Workbook-Upload.',
                $controlId,
                self::DIMENSION_LABEL[$category] ?? $category,
            );
            $dataSourceMapping = [
                'section'              => $section,
                'source'               => 'VDA-ISA 6.0 skeleton (numbers only, ENX-licence compliant)',
                'loaded_by'            => self::class,
                'maturityTargetSource' => 'vda_isa_default',
            ];

            $existing = $this->requirementRepository->findOneBy([
                'framework'     => $framework,
                'requirementId' => $controlId,
                'uploadTenant'  => null,
            ]);

            if ($existing instanceof ComplianceRequirement) {
                if ($update) {
                    $existing->setTitle($title)
                        ->setDescription($description)
                        ->setCategory($category)
                        ->setRequirementType('core')
                        ->setPriority('medium')
                        ->setMaturityTarget('established')
                        ->setDataSourceMapping($dataSourceMapping);
                    $updated++;
                } else {
                    $skipped++;
                }
                continue;
            }

            $requirement = new ComplianceRequirement();
            $requirement->setFramework($framework)
                ->setRequirementId($controlId)
                ->setTitle($title)
                ->setDescription($description)
                ->setCategory($category)
                ->setRequirementType('core')
                ->setPriority('medium')
                ->setMaturityTarget('established')
                ->setDataSourceMapping($dataSourceMapping);
            $this->em->persist($requirement);
            $created++;
        }

        $this->em->flush();

        return [
            'framework' => $framework,
            'created'   => $created,
            'updated'   => $updated,
            'skipped'   => $skipped,
            'total'     => count($requirements),
        ];
    }

    /** @return array<string, mixed> */
    private function yaml(): array
    {
        if ($this->yaml === null) {
            $path = $this->projectDir . '/fixtures/library/frameworks/vda-isa-tisax-v6.yaml';
            $this->yaml = is_file($path) ? (Yaml::parseFile($path) ?: []) : [];
        }
        return $this->yaml;
    }
}
