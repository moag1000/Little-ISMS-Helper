<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cross-Framework Mapping Importer (Sprint 2 / A3).
 *
 * Generic importer for consultant-delivered mapping tables.
 * Accepts CSV in the following layout (RFC 4180, UTF-8, BOM tolerated):
 *
 *     source_requirement_id,target_requirement_id,mapping_type,mapping_percentage,confidence,bidirectional,rationale
 *     A.5.1,NIS2-21.2.a,full,100,high,true,"27001 Policies cover NIS2 policy requirement"
 *     A.5.15,NIS2-21.2.h,full,100,high,true,"Access Control maps to NIS2 Zugriffskontrolle"
 *
 * The `source_framework_code` and `target_framework_code` are supplied
 * as constructor arguments to the command (not in the CSV itself) so
 * one file cleanly maps exactly one framework pair — matches how
 * consultants deliver them.
 *
 * Matching logic:
 *  - rows lookup by (framework, requirementId); missing rows are
 *    reported in the `warnings` bucket but do not abort the run
 *  - existing source/target pair is skipped (idempotent)
 *  - `mapping_percentage` defaults to derived-from-type when omitted:
 *      full=100, partial=70, weak=30, exceeds=120
 *  - `bidirectional` accepts: true/false/1/0/yes/no (case-insensitive)
 */
final class CrossFrameworkMappingImporter
{
    public const DEFAULT_PERCENTAGE = [
        'full' => 100,
        'partial' => 70,
        'weak' => 30,
        'exceeds' => 120,
    ];

    public const REQUIRED_COLUMNS = [
        'source_requirement_id',
        'target_requirement_id',
        'mapping_type',
    ];

    public const OPTIONAL_COLUMNS = [
        'mapping_percentage',
        'confidence',
        'bidirectional',
        'rationale',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly ComplianceMappingRepository $mappingRepository,
    ) {
    }

    /**
     * @return array{
     *     processed: int,
     *     created: int,
     *     skipped_existing: int,
     *     skipped_missing: int,
     *     errors: list<array{row: int, message: string}>,
     *     warnings: list<array{row: int, source: string, target: string, reason: string}>,
     * }
     */
    public function import(
        string $csv,
        string $sourceFrameworkCode,
        string $targetFrameworkCode,
        bool $persist = true,
        ?string $verifiedBy = null,
    ): array {
        $result = $this->emptyResult();

        $source = $this->frameworkRepository->findOneBy(['code' => $sourceFrameworkCode]);
        $target = $this->frameworkRepository->findOneBy(['code' => $targetFrameworkCode]);

        if (!$source instanceof ComplianceFramework) {
            $result['errors'][] = ['row' => 0, 'message' => sprintf('Source framework %s not loaded.', $sourceFrameworkCode)];
            return $result;
        }
        if (!$target instanceof ComplianceFramework) {
            $result['errors'][] = ['row' => 0, 'message' => sprintf('Target framework %s not loaded.', $targetFrameworkCode)];
            return $result;
        }

        $rows = $this->parseCsv($this->stripBom($csv));
        if ($rows === []) {
            return $result;
        }

        $header = $this->normaliseHeader(array_shift($rows));
        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($header));
        if ($missing !== []) {
            $result['errors'][] = [
                'row' => 1,
                'message' => 'Missing required column(s): ' . implode(', ', $missing),
            ];
            return $result;
        }

        foreach ($rows as $idx => $rawRow) {
            $rowNumber = $idx + 2;
            $data = $this->mapRow($rawRow, $header);
            $sourceId = $data['source_requirement_id'] ?? '';
            $targetId = $data['target_requirement_id'] ?? '';
            $type = strtolower($data['mapping_type'] ?? '');

            if ($sourceId === '' || $targetId === '' || $type === '') {
                $result['warnings'][] = [
                    'row' => $rowNumber,
                    'source' => $sourceId,
                    'target' => $targetId,
                    'reason' => 'missing required value',
                ];
                continue;
            }
            if (!array_key_exists($type, self::DEFAULT_PERCENTAGE)) {
                $result['warnings'][] = [
                    'row' => $rowNumber,
                    'source' => $sourceId,
                    'target' => $targetId,
                    'reason' => sprintf('unknown mapping_type "%s"', $type),
                ];
                continue;
            }

            $srcReq = $this->requirementRepository->findOneBy([
                'complianceFramework' => $source,
                'requirementId' => $sourceId,
            ]);
            $tgtReq = $this->requirementRepository->findOneBy([
                'complianceFramework' => $target,
                'requirementId' => $targetId,
            ]);

            if (!$srcReq instanceof ComplianceRequirement || !$tgtReq instanceof ComplianceRequirement) {
                $result['skipped_missing']++;
                $result['warnings'][] = [
                    'row' => $rowNumber,
                    'source' => $sourceId,
                    'target' => $targetId,
                    'reason' => sprintf('source=%s, target=%s', $srcReq ? 'OK' : 'MISSING', $tgtReq ? 'OK' : 'MISSING'),
                ];
                continue;
            }

            $existing = $this->mappingRepository->findOneBy([
                'sourceRequirement' => $srcReq,
                'targetRequirement' => $tgtReq,
            ]);
            if ($existing instanceof ComplianceMapping) {
                $result['skipped_existing']++;
                continue;
            }

            $mapping = new ComplianceMapping();
            $mapping->setSourceRequirement($srcReq);
            $mapping->setTargetRequirement($tgtReq);
            $mapping->setMappingType($type);
            $mapping->setMappingPercentage($this->parsePercentage($data, $type));
            $mapping->setConfidence($this->parseConfidence($data));
            $mapping->setBidirectional($this->parseBool($data['bidirectional'] ?? '', true));
            $mapping->setMappingRationale($data['rationale'] ?? null);
            $mapping->setVerifiedBy($verifiedBy ?? 'consultant_template_import');
            $mapping->setVerificationDate(new DateTimeImmutable());

            if ($persist) {
                $this->entityManager->persist($mapping);
            }
            $result['created']++;
            $result['processed']++;
        }

        if ($persist) {
            $this->entityManager->flush();
        }

        return $result;
    }

    /** @return list<list<string>> */
    private function parseCsv(string $csv): array
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open temp stream for CSV parsing.');
        }
        fwrite($handle, $csv);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $rows[] = array_map(
                static fn(mixed $v): string => is_string($v) ? $v : (string) $v,
                $row
            );
        }
        fclose($handle);
        return $rows;
    }

    private function stripBom(string $s): string
    {
        if (str_starts_with($s, "\xEF\xBB\xBF")) {
            return substr($s, 3);
        }
        return $s;
    }

    /**
     * @param list<string> $header
     * @return array<string, int>
     */
    private function normaliseHeader(array $header): array
    {
        $map = [];
        foreach ($header as $i => $col) {
            $key = strtolower(trim($col));
            if ($key !== '') {
                $map[$key] = $i;
            }
        }
        return $map;
    }

    /**
     * @param list<string> $row
     * @param array<string, int> $headerMap
     * @return array<string, string>
     */
    private function mapRow(array $row, array $headerMap): array
    {
        $out = [];
        foreach ($headerMap as $col => $idx) {
            $out[$col] = trim($row[$idx] ?? '');
        }
        return $out;
    }

    /** @param array<string, string> $data */
    private function parsePercentage(array $data, string $type): int
    {
        $raw = trim($data['mapping_percentage'] ?? '');
        if ($raw !== '' && is_numeric($raw)) {
            return max(0, min(150, (int) $raw));
        }
        return self::DEFAULT_PERCENTAGE[$type] ?? 0;
    }

    /** @param array<string, string> $data */
    private function parseConfidence(array $data): string
    {
        $raw = strtolower(trim($data['confidence'] ?? ''));
        return in_array($raw, ['low', 'medium', 'high'], true) ? $raw : 'medium';
    }

    private function parseBool(string $raw, bool $default): bool
    {
        $norm = strtolower(trim($raw));
        if ($norm === '') {
            return $default;
        }
        return in_array($norm, ['true', '1', 'yes', 'y'], true);
    }

    private function emptyResult(): array
    {
        return [
            'processed' => 0,
            'created' => 0,
            'skipped_existing' => 0,
            'skipped_missing' => 0,
            'errors' => [],
            'warnings' => [],
        ];
    }
}
