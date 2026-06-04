<?php

declare(strict_types=1);

namespace App\Service\Import\Schema;

use App\Entity\Tenant;
use App\Service\Import\Mapper\AbstractEntityMapper;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Generic, schema-driven entity mapper.
 *
 * Replaces hand-written per-entity mapper code: one instance is bound to one
 * {@see EntityImportSchema} (built on demand by {@see ImportSchemaRegistry}) and
 * drives validation, casting, FK-resolution and dedup entirely from the schema.
 *
 * MODULE-AWARENESS (key invariant): a field whose `module` is not active for the
 * tenant is treated as if it did not exist — it is never required, never
 * validated, and never written. The offered column set (sample / mapping
 * suggestions) is filtered the same way via {@see activeFields()}, so users
 * never see columns for features they don't have.
 */
final class SchemaDrivenMapper extends AbstractEntityMapper
{
    public function __construct(
        EntityManagerInterface $em,
        private readonly ModuleConfigurationService $moduleConfiguration,
        private readonly EntityImportSchema $schema,
    ) {
        parent::__construct($em);
    }

    public function supportsEntityType(string $entityType): bool
    {
        return $entityType === $this->schema->entityType;
    }

    /**
     * Fields that are active for the current tenant — module-gated fields whose
     * module is inactive are dropped.
     *
     * @return list<ImportFieldSpec>
     */
    public function activeFields(): array
    {
        return array_values(array_filter(
            $this->schema->fields,
            fn(ImportFieldSpec $f): bool => $f->module === null || $this->moduleConfiguration->isModuleActive($f->module),
        ));
    }

    public function validate(array $row): array
    {
        $errors   = [];
        $warnings = [];

        foreach ($this->activeFields() as $field) {
            $raw = $this->resolveField($row, $field->name, null);
            $isEmpty = $raw === null || $raw === '';

            if ($field->required && $isEmpty) {
                $errors[] = sprintf('Field "%s" is required.', $field->label ?? $field->name);
                continue;
            }

            if ($isEmpty) {
                continue;
            }

            if ($field->type === ImportFieldSpec::TYPE_ENUM
                && $field->enumValues !== []
                && !in_array(strtolower(trim((string) $raw)), $field->enumValues, strict: true)
            ) {
                $errors[] = sprintf(
                    'Field "%s" value "%s" is not allowed. Allowed: %s.',
                    $field->label ?? $field->name,
                    $raw,
                    implode(', ', $field->enumValues),
                );
            }

            if (in_array($field->type, [ImportFieldSpec::TYPE_INT, ImportFieldSpec::TYPE_FLOAT], strict: true)
                && !is_numeric((string) $raw)
            ) {
                $errors[] = sprintf('Field "%s" must be numeric (got "%s").', $field->label ?? $field->name, $raw);
            }

            if ($field->type === ImportFieldSpec::TYPE_DATE && $this->castDateTime($raw) === null) {
                $warnings[] = sprintf('Field "%s" date "%s" could not be parsed and will be skipped.', $field->label ?? $field->name, $raw);
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    public function toEntityData(array $row, ?array $columnMapping = null, ?Tenant $tenant = null): array
    {
        $data = [];

        foreach ($this->activeFields() as $field) {
            $raw = $this->resolveField($row, $field->name, $columnMapping);
            if ($raw === null || $raw === '') {
                continue;
            }

            $value = match ($field->type) {
                ImportFieldSpec::TYPE_INT      => $this->castInt($raw),
                ImportFieldSpec::TYPE_FLOAT    => $this->castFloat($raw),
                ImportFieldSpec::TYPE_BOOL     => $this->castBool($raw),
                ImportFieldSpec::TYPE_DATE     => $this->castDateTime($raw),
                ImportFieldSpec::TYPE_ENUM     => $this->castEnum($raw, $field->enumValues),
                ImportFieldSpec::TYPE_LIST     => $this->castList($raw),
                ImportFieldSpec::TYPE_RELATION => $this->resolveRelation($field, $raw, $tenant),
                default                        => trim((string) $raw),
            };

            if ($value !== null) {
                // Map the canonical field name to the entity property (setter without "set").
                $property = lcfirst(substr($field->setter, 3));
                $data[$property] = $value;
            }
        }

        return $data;
    }

    public function findExisting(array $row, Tenant $tenant): ?object
    {
        $uniqueFields = array_filter($this->activeFields(), fn(ImportFieldSpec $f): bool => $f->unique);
        if ($uniqueFields === []) {
            return null;
        }

        $criteria = ['tenant' => $tenant];
        foreach ($uniqueFields as $field) {
            $raw = $this->resolveField($row, $field->name, null);
            if ($raw === null || $raw === '') {
                return null;
            }
            $property = lcfirst(substr($field->setter, 3));
            $criteria[$property] = trim((string) $raw);
        }

        return $this->em->getRepository($this->schema->entityClass)->findOneBy($criteria);
    }

    /**
     * Split a delimited cell ("a, b; c") into a trimmed, non-empty list.
     *
     * @return list<string>
     */
    private function castList(mixed $raw): array
    {
        $parts = preg_split('/[;,]/', (string) $raw) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn(string $s): bool => $s !== ''));
    }

    /**
     * Resolve a relation cell to the related entity by looking it up via the
     * configured lookup field, scoped to the tenant. Returns null when not found
     * (the row keeps the relation empty rather than failing the whole import).
     */
    private function resolveRelation(ImportFieldSpec $field, mixed $raw, ?Tenant $tenant): ?object
    {
        if ($field->relationClass === null || $field->relationLookup === null || $tenant === null) {
            return null;
        }

        return $this->em->getRepository($field->relationClass)->findOneBy([
            $field->relationLookup => trim((string) $raw),
            'tenant'               => $tenant,
        ]);
    }
}
