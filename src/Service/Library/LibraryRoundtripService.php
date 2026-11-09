<?php

declare(strict_types=1);

namespace App\Service\Library;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Repository\ComplianceRequirementRepository;
use App\Util\CsvSanitizer;
use Symfony\Component\Yaml\Yaml;

/**
 * Round-trip export: ComplianceFramework + its requirements → YAML / CSV.
 *
 * The YAML output matches the import format used by BsiKompendiumImporter
 * and VdaIsaImporter, enabling lossless round-trips:
 *   importYaml → persist → exportYaml → importYaml again → same result
 *
 * CSV export produces a flat structure suitable for spreadsheet review.
 *
 * Not final so it can be mocked in tests.
 */
final class LibraryRoundtripService
{
    public function __construct(
        private readonly ComplianceRequirementRepository $requirementRepository,
    ) {
    }

    /**
     * Export a ComplianceFramework and all its requirements to YAML.
     *
     * The output structure matches the import YAML format used by the
     * BSI and TISAX importers. Root-level requirements become "bausteine"
     * with their children as "anforderungen".
     */
    public function exportYaml(ComplianceFramework $framework, ?Tenant $tenant = null): string
    {
        $data = $this->buildExportData($framework, $tenant);

        return Yaml::dump($data, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /**
     * Export a ComplianceFramework and all its requirements to CSV.
     *
     * Columns: framework_code, framework_name, framework_version,
     *          requirement_id, parent_requirement_id, title, description,
     *          category, priority, requirement_type
     */
    public function exportCsv(ComplianceFramework $framework, ?Tenant $tenant = null): string
    {
        $rows = [];

        // Header row
        $rows[] = [
            'framework_code',
            'framework_name',
            'framework_version',
            'requirement_id',
            'parent_requirement_id',
            'title',
            'description',
            'category',
            'priority',
            'requirement_type',
        ];

        $requirements = $this->requirementsOf($framework, $tenant);

        foreach ($requirements as $req) {
            $rows[] = [
                $framework->getCode() ?? '',
                $framework->getName() ?? '',
                $framework->getVersion() ?? '',
                $req->getRequirementId() ?? '',
                $req->getParentRequirement()?->getRequirementId() ?? '',
                $req->getTitle() ?? '',
                $req->getDescription() ?? '',
                $req->getCategory() ?? '',
                $req->getPriority() ?? '',
                $req->getRequirementType(),
            ];
        }

        $buffer = fopen('php://temp', 'r+');
        if ($buffer === false) {
            return '';
        }

        foreach ($rows as $row) {
            fputcsv($buffer, array_map(CsvSanitizer::sanitize(...), $row), ',', '"', '\\');
        }

        rewind($buffer);
        $csv = stream_get_contents($buffer);
        fclose($buffer);

        return $csv !== false ? $csv : '';
    }

    /**
     * Build the export data array (shared by exportYaml + tests).
     *
     * @return array<string, mixed>
     */
    public function buildExportData(ComplianceFramework $framework, ?Tenant $tenant = null): array
    {
        $metadata = [
            'code' => $framework->getCode(),
            'name' => $framework->getName(),
            'version' => $framework->getVersion(),
            'body' => $framework->getRegulatoryBody(),
            'applicableTo' => [$framework->getApplicableIndustry()],
            'exportedAt' => (new \DateTimeImmutable())->format('Y-m-d'),
        ];

        // Load all requirements and organise hierarchically
        $allRequirements = $this->requirementsOf($framework, $tenant);

        // Separate root (core) and children (detailed)
        $roots = [];
        $children = [];
        foreach ($allRequirements as $req) {
            if ($req->getParentRequirement() === null) {
                $roots[] = $req;
            } else {
                $parentId = $req->getParentRequirement()->getRequirementId();
                $children[$parentId ?? ''][] = $req;
            }
        }

        $bausteine = [];
        foreach ($roots as $root) {
            $baustein = [
                'id' => $root->getRequirementId(),
                'name' => $root->getTitle(),
                'schicht' => $root->getCategory(),
                'description' => $root->getDescription(),
                'anforderungen' => [],
            ];

            $rootId = $root->getRequirementId() ?? '';
            foreach ($children[$rootId] ?? [] as $child) {
                $baustein['anforderungen'][] = [
                    'id' => $child->getRequirementId(),
                    'level' => match ($child->getPriority()) {
                        'high', 'critical' => 'erhoeht',
                        'medium' => 'standard',
                        default => 'basis',
                    },
                    'title' => $child->getTitle(),
                    'text' => $child->getDescription(),
                ];
            }

            $bausteine[] = $baustein;
        }

        return [
            'metadata' => $metadata,
            'bausteine' => $bausteine,
        ];
    }

    /**
     * The catalogue rows plus the exporting organisation's own uploads. A row
     * another organisation uploaded (TISAX self-assessment, `uploadTenant`) is
     * that organisation's data — the Doctrine tenant filter does not know the
     * column, so the export filters it here.
     *
     * @return list<ComplianceRequirement>
     */
    private function requirementsOf(ComplianceFramework $framework, ?Tenant $tenant): array
    {
        $rows = $this->requirementRepository->findBy(['framework' => $framework], ['requirementId' => 'ASC']);

        return array_values(array_filter(
            $rows,
            static fn (ComplianceRequirement $r): bool => $r->getUploadTenant() === null
                || ($tenant !== null && $r->getUploadTenant()->getId() === $tenant->getId()),
        ));
    }
}
