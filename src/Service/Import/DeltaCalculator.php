<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Exception\Import\ImportRowInvalidException;
use App\Service\Import\Dto\DeltaConfig;
use App\Service\Import\Dto\DeltaResult;
use App\Service\Import\Dto\ParsedSpreadsheet;
use App\Service\Import\Mapper\EntityMapperInterface;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Core delta engine for the bulk-import pipeline.
 *
 * Given a ParsedSpreadsheet and a DeltaConfig it:
 *   1. Validates each row through the entity mapper.
 *   2. Resolves whether a matching entity already exists.
 *   3. Computes a field-level diff between persisted and incoming values.
 *   4. Optionally identifies entities absent from the sheet (delete candidates).
 *
 * The DeltaCalculator is intentionally read-only: it NEVER persists or removes
 * entities — that responsibility belongs to the BulkImportOrchestrator.
 */
final class DeltaCalculator
{
    public function __construct(
        private readonly EntityMapperRegistry $registry,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Run the delta calculation for a full parsed spreadsheet.
     *
     * @param ParsedSpreadsheet  $parsed        Pre-parsed sheet from SpreadsheetParser
     * @param DeltaConfig        $config        Configuration controlling entity type, tenant, and flags
     * @param array<string, string>|null $columnMapping Optional manual column → property override
     */
    public function calculate(
        ParsedSpreadsheet $parsed,
        DeltaConfig $config,
        ?array $columnMapping = null,
    ): DeltaResult {
        $mapper = $this->registry->getMapperFor($config->entityType);

        $creates   = [];
        $updates   = [];
        $unchanged = [];
        $errors    = [];

        /** IDs of entities already matched (for delete-candidate subtraction) */
        $matchedIds = [];
        /** @var array<string, int> unique key of a new row => row number that first used it */
        $newKeys = [];

        foreach ($parsed->rows as $index => $row) {
            $rowNumber  = $index + 1; // 1-based for human-readable output
            $validation = $mapper->validate($row, $columnMapping);

            if ($validation['errors'] !== []) {
                $errors[] = [
                    'rowNumber' => $rowNumber,
                    'data'      => $row,
                    'errors'    => $validation['errors'],
                ];
                continue;
            }

            $existing = $mapper->findExisting($row, $config->tenant, $columnMapping);

            if ($existing === null) {
                // A second new row with the same unique key would fail the flush and
                // abort the whole commit — reject it here as a row error instead.
                $key = $mapper->duplicateKey($row, $columnMapping);
                if ($key !== null && isset($newKeys[$key])) {
                    $errors[] = [
                        'rowNumber' => $rowNumber,
                        'data'      => $row,
                        'errors'    => [sprintf('Duplicate key: same as row %d of this file.', $newKeys[$key])],
                    ];
                    continue;
                }
                $newValues = $this->mapRow($mapper, $row, $columnMapping, $config, $rowNumber, $errors);
                if ($newValues === null) {
                    continue;
                }
                if ($key !== null) {
                    $newKeys[$key] = $rowNumber;
                }

                $creates[] = [
                    'rowNumber' => $rowNumber,
                    'data'      => $row,
                    'entityId'  => null,
                    'oldValues' => null,
                    'newValues' => $newValues,
                    'diff'      => null,
                ];
            } else {
                $newValues = $this->mapRow($mapper, $row, $columnMapping, $config, $rowNumber, $errors);
                if ($newValues === null) {
                    continue;
                }
                // Evidence is not overwritten on an existing record (EvidenceFieldFilter): no diff for it.
                $newValues = EvidenceFieldFilter::forUpdate($existing::class, $newValues);
                $entityId  = $existing->getId();
                $oldValues = $this->getEntityValues($existing);
                $diff      = $this->computeDiff($oldValues, $newValues, $config->ignoredFields);

                if ($diff === []) {
                    $unchanged[] = [
                        'rowNumber' => $rowNumber,
                        'data'      => $row,
                        'entityId'  => $entityId,
                        'oldValues' => $oldValues,
                        'newValues' => $newValues,
                        'diff'      => [],
                    ];
                } else {
                    $updates[] = [
                        'rowNumber' => $rowNumber,
                        'data'      => $row,
                        'entityId'  => $entityId,
                        'oldValues' => $oldValues,
                        'newValues' => $newValues,
                        'diff'      => $diff,
                    ];
                }

                if ($entityId !== null) {
                    $matchedIds[$entityId] = true;
                }
            }
        }

        $deletes = [];

        if ($config->includeDeletes) {
            $fqcn       = 'App\\Entity\\' . $config->entityType;
            $repository = $this->em->getRepository($fqcn);
            $allEntities = $repository->findBy(['tenant' => $config->tenant]);

            foreach ($allEntities as $entity) {
                $entityId = $entity->getId();
                if ($entityId !== null && !isset($matchedIds[$entityId])) {
                    $deletes[] = [
                        'entityId' => $entityId,
                        'snapshot' => $this->getEntityValues($entity),
                    ];
                }
            }
        }

        return new DeltaResult($creates, $updates, $unchanged, $deletes, $errors);
    }

    /**
     * Map one row with the organisation in scope, so the preview refuses what
     * the commit would refuse (e.g. an owner that does not resolve in this
     * organisation) and reports it against the row number instead of failing
     * the whole preview.
     *
     * @param array<string, mixed>       $row
     * @param array<string, string>|null $columnMapping
     * @param list<array<string, mixed>> $errors
     *
     * @return array<string, mixed>|null null when the row was recorded as an error
     */
    private function mapRow(
        EntityMapperInterface $mapper,
        array $row,
        ?array $columnMapping,
        DeltaConfig $config,
        int $rowNumber,
        array &$errors,
    ): ?array {
        try {
            return $mapper->toEntityData($row, $columnMapping, $config->tenant);
        } catch (ImportRowInvalidException $e) {
            $errors[] = [
                'rowNumber' => $rowNumber,
                'data'      => $row,
                'errors'    => $e->getErrors() !== [] ? $e->getErrors() : [$e->getMessage()],
            ];

            return null;
        }
    }

    /**
     * Extract a flat array of scalar/string entity property values via reflection.
     *
     * Skips:
     *  - Doctrine Collection properties (to-many relations)
     *  - Properties with a null value
     *
     * Normalises:
     *  - DateTimeInterface → ISO 8601 string
     *  - BackedEnum         → backing scalar value
     *
     * @return array<string, mixed>
     */
    private function getEntityValues(object $entity): array
    {
        $values     = [];
        $reflection = new \ReflectionClass($entity);

        foreach ($reflection->getProperties() as $property) {
            if (!$property->isInitialized($entity)) {
                continue;
            }

            $value = $property->getValue($entity);

            if ($value === null) {
                continue;
            }

            if ($value instanceof Collection) {
                continue;
            }

            if ($value instanceof \DateTimeInterface) {
                $value = $value->format(\DateTimeInterface::ATOM);
            } elseif ($value instanceof \BackedEnum) {
                $value = $value->value;
            }

            $values[$property->getName()] = $value;
        }

        return $values;
    }

    /**
     * Compute field-level diff between persisted and incoming value sets.
     *
     * Only fields present in both $old and $new are compared; fields missing
     * from $new are skipped (partial-update semantics).
     * Fields listed in $ignored are excluded from comparison entirely.
     *
     * Values are normalised to strings before comparison (loose equality)
     * so that integer 1 and string "1" are treated as identical.
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @param list<string>         $ignored
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function computeDiff(array $old, array $new, array $ignored): array
    {
        $diff = [];

        foreach ($new as $field => $newValue) {
            if (in_array($field, $ignored, true)) {
                continue;
            }

            if (!array_key_exists($field, $old)) {
                continue;
            }

            $oldValue = $old[$field];

            // Normalise to string for loose comparison
            $oldNorm = self::comparable($oldValue);
            $newNorm = self::comparable($newValue);
            $same    = is_numeric($oldNorm) && is_numeric($newNorm)
                ? (float) $oldNorm === (float) $newNorm // DECIMAL "7.50" vs "7.5"
                : $oldNorm === $newNorm;

            if (!$same) {
                $diff[$field] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $diff;
    }

    /**
     * String form of a persisted or mapped value — mapped values carry enum cases,
     * dates, related entities (User / Person have no string form: by id) and lists,
     * which a plain (string) cast cannot convert.
     */
    private static function comparable(mixed $value): string
    {
        return match (true) {
            $value instanceof \BackedEnum        => (string) $value->value,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            $value instanceof \Stringable        => (string) $value,
            is_object($value) && is_callable([$value, 'getId'])
                                                 => '#' . $value->getId(), // same field → same class
            is_object($value)                    => spl_object_hash($value),
            is_array($value)                     => (string) json_encode($value),
            is_bool($value)                      => $value ? '1' : '0',
            default                              => (string) $value,
        };
    }
}
