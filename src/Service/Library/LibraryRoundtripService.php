<?php

declare(strict_types=1);

namespace App\Service\Library;

use App\Entity\ComplianceFramework;
use App\Repository\ComplianceRequirementRepository;
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
class LibraryRoundtripService
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
    public function exportYaml(ComplianceFramework $framework): string
    {
        $data = $this->buildExportData($framework);

        return Yaml::dump($data, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    /**
     * Export a ComplianceFramework and all its requirements to CSV.
     *
     * Columns: framework_code, framework_name, framework_version,
     *          requirement_id, parent_requirement_id, title, description,
     *          category, priority, requirement_type
     */
    public function exportCsv(ComplianceFramework $framework): string
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

        $requirements = $this->requirementRepository->findBy(
            ['framework' => $framework],
            ['requirementId' => 'ASC'],
        );

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
            fputcsv($buffer, $row, ',', '"', '\\');
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
    public function buildExportData(ComplianceFramework $framework): array
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
        $allRequirements = $this->requirementRepository->findBy(
            ['framework' => $framework],
            ['requirementId' => 'ASC'],
        );

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
}
