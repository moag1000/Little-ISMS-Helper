<?php

declare(strict_types=1);

namespace App\Service\Import\Mapper;

use App\Entity\Control;
use App\Entity\Tenant;
use App\Repository\ControlRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import mapper for the Control entity (ISO 27001 Annex A controls).
 *
 * Required spreadsheet columns : identifier  (e.g. "5.1" or "A.5.1")
 * Optional spreadsheet columns : title, applicability (applicable|not_applicable|not_determined),
 *                                 justification
 *
 * Delta match-key: identifier (control_id, case-insensitive).
 *
 * Special behaviour:
 *   Controls are tenant-scoped (one Control record per tenant per control_id).
 *   When `findExisting` returns a match the orchestrator updates the existing record
 *   (applicability, justification) rather than inserting a duplicate.
 *
 * Applicability mapping:
 *   'applicable'      → applicable = true,  implementationStatus stays unchanged
 *   'not_applicable'  → applicable = false
 *   'not_determined'  → applicable = null (stored as null, meaning "not yet assessed")
 *   (blank)           → field omitted from entityData; no change on update
 */
final class ControlMapper extends AbstractEntityMapper
{
    /** Accepted applicability values in spreadsheet. */
    private const APPLICABILITY_VALUES = ['applicable', 'not_applicable', 'not_determined'];

    /**
     * Pattern for ISO 27001 Annex-A control identifiers.
     * Accepts "5.1", "A.5.1", "5.1.1" etc.
     */
    private const CONTROL_ID_PATTERN = '/^(?:A\.)?(\d+\.\d+(?:\.\d+)?)$/i';

    public function __construct(
        EntityManagerInterface $em,
        private readonly ControlRepository $controlRepository,
    ) {
        parent::__construct($em);
    }

    public function supportsEntityType(string $entityType): bool
    {
        return $entityType === 'Control';
    }

    /**
     * @param array<string, mixed> $row
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function validate(array $row): array
    {
        $errors   = [];
        $warnings = [];

        // ── Required: identifier ──────────────────────────────────────────────
        $identifier = $this->resolveRawIdentifier($row);
        if (empty($identifier)) {
            $errors[] = 'Field "identifier" is required (e.g. "5.1" or "A.5.1").';
        } else {
            if (!preg_match(self::CONTROL_ID_PATTERN, trim((string) $identifier))) {
                $errors[] = sprintf(
                    'Field "identifier" value "%s" does not match expected ISO 27001 format '
                    . '(e.g. "5.1", "A.5.1", "8.1.1").',
                    $identifier,
                );
            }
        }

        // ── Applicability enum ────────────────────────────────────────────────
        $applicability = $this->resolveField($row, 'applicability', null);
        if (!empty($applicability)) {
            $normalised = strtolower(trim((string) $applicability));
            if (!in_array($normalised, self::APPLICABILITY_VALUES, strict: true)) {
                $warnings[] = sprintf(
                    'Field "applicability" value "%s" is unknown (accepted: %s). Field will be skipped.',
                    $applicability,
                    implode(', ', self::APPLICABILITY_VALUES),
                );
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param array<string, mixed>      $row
     * @param array<string, string>|null $columnMapping
     * @return array<string, mixed>
     */
    public function toEntityData(array $row, ?array $columnMapping = null): array
    {
        $get = fn (string $field): mixed => $this->resolveField($row, $field, $columnMapping);

        // Normalise identifier: strip leading "A." prefix to match DB storage convention
        $rawId = $this->resolveRawIdentifier($row);
        $controlId = $this->normaliseControlId((string) $rawId);

        $data = [
            'controlId' => $controlId,
        ];

        // Optional: title / name
        $title = $get('title') ?? $get('name');
        if (!empty($title)) {
            $data['name'] = trim((string) $title);
        }

        // Applicability
        $applicability = $get('applicability');
        if (!empty($applicability)) {
            $normalised = strtolower(trim((string) $applicability));
            if (in_array($normalised, self::APPLICABILITY_VALUES, strict: true)) {
                $data['applicable'] = match ($normalised) {
                    'applicable'     => true,
                    'not_applicable' => false,
                    default          => null,   // not_determined
                };
            }
        }

        // Justification (for SoA not-applicable reasoning)
        $justification = $get('justification');
        if (!empty($justification)) {
            $data['justification'] = trim((string) $justification);
        }

        return $data;
    }

    /**
     * Delta-mode: find by controlId within tenant (case-insensitive comparison).
     *
     * @param array<string, mixed> $row
     */
    public function findExisting(array $row, Tenant $tenant): ?object
    {
        $rawId     = $this->resolveRawIdentifier($row);
        $controlId = $this->normaliseControlId((string) $rawId);

        if ($controlId === '') {
            return null;
        }

        // Use ControlRepository standard method for tenant-scoped lookup
        /** @var Control|null $control */
        $control = $this->controlRepository->findOneBy([
            'controlId' => $controlId,
            'tenant'    => $tenant,
        ]);

        return $control;
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    /**
     * Try common column aliases for the identifier field.
     *
     * @param array<string, mixed> $row
     */
    private function resolveRawIdentifier(array $row): mixed
    {
        return $this->resolveField($row, 'identifier', null)
            ?? $this->resolveField($row, 'controlId', null)
            ?? $this->resolveField($row, 'control_id', null)
            ?? $this->resolveField($row, 'id', null);
    }

    /**
     * Strip leading "A." prefix used in older ISO 27001:2013 references so
     * values are stored consistently as "5.1", "8.1.1" etc.
     */
    private function normaliseControlId(string $raw): string
    {
        $trimmed = trim($raw);
        if (preg_match('/^A\.(\d+\.\d+(?:\.\d+)?)$/i', $trimmed, $matches)) {
            return $matches[1];
        }

        return $trimmed;
    }
}
