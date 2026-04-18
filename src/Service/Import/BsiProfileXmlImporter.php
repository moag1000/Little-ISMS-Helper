<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\ImportRowEvent;
use App\Entity\ImportSession;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Importer for BSI IT-Grundschutz "Profile" XML files (bsi_profile_xml_v1).
 *
 * This importer accepts the following minimal, application-defined subset of
 * the BSI Profile XML schema. It is NOT the official BSI Compliance Suite
 * export format in full; rather, it is a pragmatic shape that covers the
 * information the compliance-mapping domain needs for round-trip use.
 *
 * Supported schema (v1):
 *
 * <?xml version="1.0" encoding="UTF-8"?>
 * <bsi-profile version="1.0" source="bsi_grundschutz_2023">
 *   <metadata>
 *     <profile-name>Baseline Protection - Critical Infrastructure</profile-name>
 *     <created>2026-04-17</created>
 *   </metadata>
 *   <mappings>
 *     <mapping>
 *       <source framework="BSI_GRUNDSCHUTZ" requirement="CON.1.A1"/>
 *       <target framework="ISO27001"         requirement="A.5.23"/>
 *       <percentage>80</percentage>
 *       <confidence>high</confidence>
 *       <rationale>BSI CON.1.A1 aligns with ISO 27001 A.5.23.</rationale>
 *     </mapping>
 *     ...
 *   </mappings>
 * </bsi-profile>
 *
 * Validation rules:
 *   - Root element must be <bsi-profile version="1.0">.
 *   - Each <mapping> must contain <source>, <target>, <percentage>, <confidence>.
 *   - Percentage must be integer in [0, 150].
 *   - Confidence must be one of: low | medium | high.
 *   - Framework codes and requirement IDs are resolved against repositories
 *     using the same candidate-id resolution as the CSV importer.
 *
 * Return value shapes deliberately match the existing CSV importer so the
 * controller can dispatch either without branching on shape.
 */
final class BsiProfileXmlImporter
{
    private const SUPPORTED_VERSION = '1.0';
    private const ALLOWED_CONFIDENCE = ['low', 'medium', 'high'];
    private const SOURCE_CATALOG_TAG = 'bsi_profile_xml_v1';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly ComplianceMappingRepository $mappingRepository,
    ) {
    }

    /**
     * Parse the XML and return preview rows + counters without writing to DB.
     *
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>, header_error: ?string}
     */
    public function analyse(string $path): array
    {
        $summary = ['new' => 0, 'update' => 0, 'conflict' => 0, 'error' => 0];

        $root = $this->loadRoot($path);
        if (is_string($root)) {
            return ['rows' => [], 'summary' => $summary, 'header_error' => $root];
        }

        $rows = [];
        $frameworkCache = [];
        $requirementCache = [];
        $lineNo = 0;

        foreach ($root->mappings->mapping ?? [] as $mapping) {
            $lineNo++;

            $parsed = $this->extractMappingData($mapping);
            if ($parsed['error'] !== null) {
                $rows[] = [
                    'line' => $lineNo,
                    'status' => 'error',
                    'message' => $parsed['error'],
                    'source_framework' => $parsed['source_framework'],
                    'source_requirement_id' => $parsed['source_requirement_id'],
                    'target_framework' => $parsed['target_framework'],
                    'target_requirement_id' => $parsed['target_requirement_id'],
                    'percentage' => $parsed['percentage_raw'],
                ];
                $summary['error']++;
                continue;
            }

            $sourceFramework = $this->getFramework($parsed['source_framework'], $frameworkCache);
            $targetFramework = $this->getFramework($parsed['target_framework'], $frameworkCache);
            if ($sourceFramework === null || $targetFramework === null) {
                $rows[] = [
                    'line' => $lineNo,
                    'status' => 'error',
                    'message' => 'compliance_import.preview.framework_missing',
                    'source_framework' => $parsed['source_framework'],
                    'source_requirement_id' => $parsed['source_requirement_id'],
                    'target_framework' => $parsed['target_framework'],
                    'target_requirement_id' => $parsed['target_requirement_id'],
                    'percentage' => (string) $parsed['percentage'],
                ];
                $summary['error']++;
                continue;
            }

            $sourceReq = $this->getRequirement($sourceFramework, $parsed['source_requirement_id'], $requirementCache);
            $targetReq = $this->getRequirement($targetFramework, $parsed['target_requirement_id'], $requirementCache);
            if ($sourceReq === null || $targetReq === null) {
                $rows[] = [
                    'line' => $lineNo,
                    'status' => 'error',
                    'message' => 'compliance_import.preview.requirement_missing',
                    'source_framework' => $parsed['source_framework'],
                    'source_requirement_id' => $parsed['source_requirement_id'],
                    'target_framework' => $parsed['target_framework'],
                    'target_requirement_id' => $parsed['target_requirement_id'],
                    'percentage' => (string) $parsed['percentage'],
                ];
                $summary['error']++;
                continue;
            }

            $existing = $this->mappingRepository->findOneBy([
                'sourceRequirement' => $sourceReq,
                'targetRequirement' => $targetReq,
                'source' => self::SOURCE_CATALOG_TAG,
            ]);

            $status = $existing instanceof ComplianceMapping ? 'update' : 'new';
            if ($status === 'update'
                && $existing instanceof ComplianceMapping
                && abs($existing->getMappingPercentage() - $parsed['percentage']) > 25
            ) {
                $status = 'conflict';
            }

            $rows[] = [
                'line' => $lineNo,
                'status' => $status,
                'message' => null,
                'source_framework' => $parsed['source_framework'],
                'source_requirement_id' => $parsed['source_requirement_id'],
                'target_framework' => $parsed['target_framework'],
                'target_requirement_id' => $parsed['target_requirement_id'],
                'percentage' => (string) $parsed['percentage'],
            ];
            $summary[$status]++;
        }

        return [
            'rows' => $rows,
            'summary' => $summary,
            'header_error' => null,
        ];
    }

    /**
     * Persist mappings to the DB. Returns the same shape as the CSV commit path.
     *
     * ISB MINOR-1: when the caller passes an $importSession + $recorder,
     * every parsed <mapping> emits one ImportRowEvent with before / after
     * state + decision so auditors can answer "how did mapping X:Y come
     * into being on date Z".
     *
     * @return array{imported: int, superseded: int, skipped: int, errors: list<string>}
     */
    public function import(
        string $path,
        ?ImportSessionRecorder $recorder = null,
        ?ImportSession $importSession = null,
    ): array {
        $imported = 0;
        $superseded = 0;
        $skipped = 0;
        $errors = [];

        $root = $this->loadRoot($path);
        if (is_string($root)) {
            return [
                'imported' => 0,
                'superseded' => 0,
                'skipped' => 0,
                'errors' => [$root],
            ];
        }

        $frameworkCache = [];
        $requirementCache = [];
        $lineNo = 0;

        foreach ($root->mappings->mapping ?? [] as $mapping) {
            $lineNo++;

            $parsed = $this->extractMappingData($mapping);
            $sourceRow = [
                'source_framework' => $parsed['source_framework'],
                'source_requirement_id' => $parsed['source_requirement_id'],
                'target_framework' => $parsed['target_framework'],
                'target_requirement_id' => $parsed['target_requirement_id'],
                'percentage' => $parsed['percentage_raw'],
                'confidence' => $parsed['confidence'],
                'rationale' => $parsed['rationale'],
            ];

            if ($parsed['error'] !== null) {
                $errors[] = sprintf('Mapping %d: %s', $lineNo, $parsed['error']);
                $skipped++;
                $this->recordIfEnabled(
                    $recorder, $importSession, $lineNo,
                    ImportRowEvent::DECISION_ERROR,
                    null, null, null, null, $sourceRow,
                    $parsed['error'],
                );
                continue;
            }

            $sourceFramework = $this->getFramework($parsed['source_framework'], $frameworkCache);
            $targetFramework = $this->getFramework($parsed['target_framework'], $frameworkCache);
            if ($sourceFramework === null || $targetFramework === null) {
                $errMsg = sprintf(
                    'Mapping %d: framework not found (%s -> %s)',
                    $lineNo,
                    $parsed['source_framework'],
                    $parsed['target_framework'],
                );
                $errors[] = $errMsg;
                $skipped++;
                $this->recordIfEnabled(
                    $recorder, $importSession, $lineNo,
                    ImportRowEvent::DECISION_ERROR,
                    null, null, null, null, $sourceRow, $errMsg,
                );
                continue;
            }

            $sourceReq = $this->getRequirement($sourceFramework, $parsed['source_requirement_id'], $requirementCache);
            $targetReq = $this->getRequirement($targetFramework, $parsed['target_requirement_id'], $requirementCache);
            if ($sourceReq === null || $targetReq === null) {
                $missing = [];
                if ($sourceReq === null) {
                    $missing[] = sprintf('source=%s:%s', $sourceFramework->getCode(), $parsed['source_requirement_id']);
                }
                if ($targetReq === null) {
                    $missing[] = sprintf('target=%s:%s', $targetFramework->getCode(), $parsed['target_requirement_id']);
                }
                $errMsg = sprintf('Mapping %d: %s', $lineNo, implode(', ', $missing));
                $errors[] = $errMsg;
                $skipped++;
                $this->recordIfEnabled(
                    $recorder, $importSession, $lineNo,
                    ImportRowEvent::DECISION_ERROR,
                    null, null, null, null, $sourceRow, $errMsg,
                );
                continue;
            }

            $existing = $this->mappingRepository->findOneBy([
                'sourceRequirement' => $sourceReq,
                'targetRequirement' => $targetReq,
                'source' => self::SOURCE_CATALOG_TAG,
            ]);

            $beforeState = null;
            if ($existing instanceof ComplianceMapping) {
                $beforeState = [
                    'mapping_percentage' => $existing->getMappingPercentage(),
                    'confidence' => $existing->getConfidence(),
                    'rationale' => $existing->getMappingRationale(),
                    'version' => $existing->getVersion(),
                    'valid_from' => $existing->getValidFrom()?->format(DATE_ATOM),
                ];
                $existing->setValidUntil(new DateTimeImmutable());
                $superseded++;
            }

            $new = (new ComplianceMapping())
                ->setSourceRequirement($sourceReq)
                ->setTargetRequirement($targetReq)
                ->setMappingPercentage($parsed['percentage'])
                ->setConfidence($parsed['confidence'])
                ->setBidirectional(false)
                ->setMappingRationale($parsed['rationale'])
                ->setSource(self::SOURCE_CATALOG_TAG)
                ->setVersion(($existing?->getVersion() ?? 0) + 1)
                ->setValidFrom(new DateTimeImmutable());

            $this->entityManager->persist($new);
            $imported++;

            // Flush so the new mapping gets an ID we can record on the event.
            $this->entityManager->flush();

            $afterState = [
                'mapping_percentage' => $new->getMappingPercentage(),
                'confidence' => $new->getConfidence(),
                'rationale' => $new->getMappingRationale(),
                'version' => $new->getVersion(),
                'valid_from' => $new->getValidFrom()?->format(DATE_ATOM),
            ];

            $this->recordIfEnabled(
                $recorder, $importSession, $lineNo,
                $existing instanceof ComplianceMapping
                    ? ImportRowEvent::DECISION_UPDATE
                    : ImportRowEvent::DECISION_IMPORT,
                'ComplianceMapping', $new->getId(),
                $beforeState, $afterState, $sourceRow, null,
            );
        }

        $this->entityManager->flush();

        return [
            'imported' => $imported,
            'superseded' => $superseded,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed>|null $beforeState
     * @param array<string, mixed>|null $afterState
     * @param array<string, mixed>|null $sourceRow
     */
    private function recordIfEnabled(
        ?ImportSessionRecorder $recorder,
        ?ImportSession $session,
        int $lineNumber,
        string $decision,
        ?string $targetEntityType,
        ?int $targetEntityId,
        ?array $beforeState,
        ?array $afterState,
        ?array $sourceRow,
        ?string $errorMessage,
    ): void {
        if ($recorder === null || $session === null) {
            return;
        }

        $recorder->recordRow(
            $session,
            $lineNumber,
            $decision,
            $targetEntityType,
            $targetEntityId,
            $beforeState,
            $afterState,
            $sourceRow,
            $errorMessage,
        );
    }

    /**
     * Load and validate the XML root element. Returns the SimpleXMLElement on
     * success, or a translation key string on failure.
     */
    private function loadRoot(string $path): \SimpleXMLElement|string
    {
        if (!is_file($path) || !is_readable($path)) {
            return 'compliance_import.preview.file_unreadable';
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $xml = @simplexml_load_file($path);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return 'compliance_import.preview.xml_parse_error';
        }

        if ($xml->getName() !== 'bsi-profile') {
            return 'compliance_import.preview.xml_parse_error';
        }

        $version = (string) ($xml['version'] ?? '');
        if ($version !== self::SUPPORTED_VERSION) {
            return 'compliance_import.preview.xml_parse_error';
        }

        if (!isset($xml->mappings)) {
            return 'compliance_import.preview.xml_parse_error';
        }

        return $xml;
    }

    /**
     * Extract and validate one <mapping> element.
     *
     * @return array{
     *     source_framework: string,
     *     source_requirement_id: string,
     *     target_framework: string,
     *     target_requirement_id: string,
     *     percentage: int,
     *     percentage_raw: string,
     *     confidence: string,
     *     rationale: ?string,
     *     error: ?string
     * }
     */
    private function extractMappingData(\SimpleXMLElement $mapping): array
    {
        $sourceFramework = (string) ($mapping->source['framework'] ?? '');
        $sourceRequirement = (string) ($mapping->source['requirement'] ?? '');
        $targetFramework = (string) ($mapping->target['framework'] ?? '');
        $targetRequirement = (string) ($mapping->target['requirement'] ?? '');
        $percentageRaw = trim((string) ($mapping->percentage ?? ''));
        $confidence = strtolower(trim((string) ($mapping->confidence ?? '')));
        $rationale = trim((string) ($mapping->rationale ?? ''));

        $error = null;

        if ($sourceFramework === '' || $sourceRequirement === ''
            || $targetFramework === '' || $targetRequirement === ''
        ) {
            $error = 'compliance_import.preview.xml_mapping_incomplete';
        } elseif ($percentageRaw === '' || !ctype_digit($percentageRaw)) {
            $error = 'compliance_import.preview.xml_percentage_invalid';
        } elseif ((int) $percentageRaw < 0 || (int) $percentageRaw > 150) {
            $error = 'compliance_import.preview.xml_percentage_invalid';
        } elseif (!in_array($confidence, self::ALLOWED_CONFIDENCE, true)) {
            $error = 'compliance_import.preview.xml_confidence_invalid';
        }

        return [
            'source_framework' => $sourceFramework,
            'source_requirement_id' => $sourceRequirement,
            'target_framework' => $targetFramework,
            'target_requirement_id' => $targetRequirement,
            'percentage' => (int) $percentageRaw,
            'percentage_raw' => $percentageRaw,
            'confidence' => $confidence,
            'rationale' => $rationale !== '' ? $rationale : null,
            'error' => $error,
        ];
    }

    /**
     * @param array<string, ComplianceFramework|null> $cache
     */
    private function getFramework(string $code, array &$cache): ?ComplianceFramework
    {
        if (!array_key_exists($code, $cache)) {
            $cache[$code] = $this->frameworkRepository->findOneBy(['code' => $code]);
        }

        return $cache[$code];
    }

    /**
     * @param array<string, ComplianceRequirement|null> $cache
     */
    private function getRequirement(
        ComplianceFramework $framework,
        string $requirementId,
        array &$cache,
    ): ?ComplianceRequirement {
        $key = $framework->getCode() . '::' . $requirementId;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $candidates = $this->candidateIds($framework, $requirementId);
        foreach ($candidates as $candidate) {
            $hit = $this->requirementRepository->findOneBy([
                'complianceFramework' => $framework,
                'requirementId' => $candidate,
            ]);
            if ($hit instanceof ComplianceRequirement) {
                $cache[$key] = $hit;

                return $hit;
            }
        }

        $cache[$key] = null;

        return null;
    }

    /**
     * @return list<string>
     */
    private function candidateIds(ComplianceFramework $framework, string $id): array
    {
        $candidates = [$id];
        $stripped = preg_replace('/^(Art\.|§)/i', '', $id) ?? $id;
        $strippedAnnex = preg_replace('/^A\./i', '', $stripped) ?? $stripped;
        foreach ([$stripped, $strippedAnnex] as $variant) {
            if ($variant !== null && $variant !== '' && $variant !== $id) {
                $candidates[] = $variant;
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }
}
