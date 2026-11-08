<?php

declare(strict_types=1);

namespace App\Service\Import\Schema;

use App\Entity\Tenant;
use App\Entity\User;
use App\Exception\Import\ImportRowInvalidException;
use App\Service\Import\Mapper\AbstractEntityMapper;
use App\Service\ModuleConfigurationService;
use App\Service\Owner\OwnerText;
use App\Service\Owner\OwnerTextResolver;
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
    private const NUMERIC_TYPES = [
        ImportFieldSpec::TYPE_INT,
        ImportFieldSpec::TYPE_FLOAT,
        ImportFieldSpec::TYPE_DECIMAL,
    ];

    public function __construct(
        EntityManagerInterface $em,
        private readonly ModuleConfigurationService $moduleConfiguration,
        private readonly EntityImportSchema $schema,
        private readonly ?OwnerTextResolver $ownerTexts = null,
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

    public function validate(array $row, ?array $columnMapping = null): array
    {
        $errors   = [];
        $warnings = [];

        foreach ($this->activeFields() as $field) {
            $raw = $this->resolveField($row, $field->name, $columnMapping);
            $isEmpty = $raw === null || $raw === '';

            if ($field->required && $isEmpty && $this->anyGiven($row, $field->requiredUnlessAny, $columnMapping)) {
                continue; // satisfied by an alternative (user OR person OR free text)
            }

            if ($field->required && $isEmpty) {
                $errors[] = sprintf('Field "%s" is required.', $field->label ?? $field->name);
                continue;
            }

            if ($isEmpty) {
                continue;
            }

            if ($field->type === ImportFieldSpec::TYPE_ENUM && !$this->enumAccepts($field, $raw)) {
                $errors[] = sprintf(
                    'Field "%s" value "%s" is not allowed. Allowed: %s.',
                    $field->label ?? $field->name,
                    $raw,
                    implode(', ', $this->allowedEnumValues($field)),
                );
            }

            if (in_array($field->type, self::NUMERIC_TYPES, strict: true) && !is_numeric(trim((string) $raw))) {
                $errors[] = sprintf('Field "%s" must be numeric (got "%s").', $field->label ?? $field->name, $raw);
            }

            if ($field->type === ImportFieldSpec::TYPE_DATE && $this->castDateTime($raw) === null) {
                $warnings[] = sprintf('Field "%s" date "%s" could not be parsed and will be skipped.', $field->label ?? $field->name, $raw);
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param list<string>               $fieldNames
     * @param array<string, string>|null $columnMapping
     */
    private function anyGiven(array $row, array $fieldNames, ?array $columnMapping): bool
    {
        foreach ($fieldNames as $name) {
            $value = $this->resolveField($row, $name, $columnMapping);
            if ($value !== null && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    public function toEntityData(array $row, ?array $columnMapping = null, ?Tenant $tenant = null): array
    {
        $data = [];
        $ownerTexts = [];

        foreach ($this->activeFields() as $field) {
            $raw = $this->resolveField($row, $field->name, $columnMapping);
            if ($raw === null || $raw === '') {
                continue;
            }
            if ($field->type === ImportFieldSpec::TYPE_OWNER) {
                $ownerTexts[] = [$field, trim((string) $raw)];
                continue;
            }

            $value = match ($field->type) {
                ImportFieldSpec::TYPE_INT      => $this->castInt($raw),
                ImportFieldSpec::TYPE_FLOAT    => $this->castFloat($raw),
                ImportFieldSpec::TYPE_DECIMAL  => $this->castDecimal($raw),
                ImportFieldSpec::TYPE_BOOL     => $this->castBool($raw),
                ImportFieldSpec::TYPE_DATE     => $this->castDateTime($raw),
                ImportFieldSpec::TYPE_ENUM     => $field->enumClass !== null
                    ? $this->castBackedEnum($raw, $field->enumClass)
                    : $this->castEnum($raw, $field->enumValues),
                ImportFieldSpec::TYPE_LIST     => $this->castList($raw),
                ImportFieldSpec::TYPE_RELATION => $this->resolveRelation($field, $raw, $tenant),
                default                        => trim((string) $raw),
            };

            if ($value === null && $tenant !== null && $field->refuseIfUnresolved && trim((string) $raw) !== '') {
                // Preview and commit: a given owner that does not resolve in this
                // organisation (unknown, or belonging to another one) must not be
                // dropped silently.
                $message = sprintf(
                    'Field "%s": "%s" could not be resolved in this organisation.',
                    $field->label ?? $field->name,
                    trim((string) $raw),
                );

                // The delta calculator / orchestrator record it against their row number.
                throw new ImportRowInvalidException(0, [$message], $message);
            }

            if ($value !== null) {
                // Map the canonical field name to the entity property (setter without "set").
                $property = lcfirst(substr($field->setter, 3));
                $data[$property] = $value;
            }
        }

        foreach ($ownerTexts as [$field, $text]) {
            $this->applyOwnerText($data, $field, $text, $tenant);
        }

        return $data;
    }

    /**
     * An owner given as text fills the record's User or Person slot — unless
     * the row also names the user or person in their own columns, which win.
     * Known names resolve now (the preview shows the match); an unknown name
     * travels as {@see OwnerText} and becomes a Person of the organisation on commit.
     *
     * @param array<string, mixed> $data
     */
    private function applyOwnerText(array &$data, ImportFieldSpec $field, string $text, ?Tenant $tenant): void
    {
        $userProperty = lcfirst(substr($field->setter, 3));
        $personProperty = lcfirst(substr((string) $field->personSetter, 3));
        if ($text === '' || $field->personSetter === null
            || array_key_exists($userProperty, $data) || array_key_exists($personProperty, $data)
        ) {
            return;
        }

        $target = $this->ownerTexts?->find($tenant, $text);
        if ($target instanceof User) {
            $data[$userProperty] = $target;
            $data[$personProperty] = null;
        } elseif ($target !== null) {
            $data[$userProperty] = null;
            $data[$personProperty] = $target;
        } else {
            $data[$userProperty] = null;
            $data[$personProperty] = new OwnerText($text, $field->setter, $field->personSetter);
        }
    }

    public function findExisting(array $row, Tenant $tenant, ?array $columnMapping = null): ?object
    {
        $uniqueFields = array_filter($this->activeFields(), fn(ImportFieldSpec $f): bool => $f->unique);
        if ($uniqueFields === []) {
            return null;
        }

        $criteria = ['tenant' => $tenant];
        foreach ($uniqueFields as $field) {
            $raw = $this->resolveField($row, $field->name, $columnMapping);
            if ($raw === null || $raw === '') {
                return null;
            }
            $property = lcfirst(substr($field->setter, 3));
            $criteria[$property] = trim((string) $raw);
        }

        return $this->em->getRepository($this->schema->entityClass)->findOneBy($criteria);
    }

    public function duplicateKey(array $row, ?array $columnMapping = null): ?string
    {
        $parts = [];
        foreach (array_filter($this->activeFields(), fn(ImportFieldSpec $f): bool => $f->unique) as $field) {
            $raw = $this->resolveField($row, $field->name, $columnMapping);
            if ($raw === null || $raw === '') {
                return null;
            }
            $parts[] = mb_strtolower(trim((string) $raw));
        }

        return $parts === [] ? null : implode("\x1F", $parts);
    }

    /**
     * A numeric cell as a numeric string — the type Doctrine DECIMAL columns and
     * their `?string` setters take (a float would be a TypeError under strict types).
     */
    private function castDecimal(mixed $raw): ?string
    {
        $value = trim((string) $raw);

        return is_numeric($value) ? $value : null;
    }

    /** The value list must contain the cell AND, with an enum class, a case must match it. */
    private function enumAccepts(ImportFieldSpec $field, mixed $raw): bool
    {
        if ($field->enumValues !== [] && !in_array(strtolower(trim((string) $raw)), $field->enumValues, strict: true)) {
            return false;
        }

        return $field->enumClass === null || $this->castBackedEnum($raw, $field->enumClass) !== null;
    }

    /** @return list<string> */
    private function allowedEnumValues(ImportFieldSpec $field): array
    {
        if ($field->enumValues !== [] || $field->enumClass === null) {
            return $field->enumValues;
        }

        return array_map(static fn(\BackedEnum $case): string => (string) $case->value, $field->enumClass::cases());
    }

    /**
     * The BackedEnum case whose value matches the cell, case-insensitive; null when none does.
     *
     * @param class-string<\BackedEnum> $enumClass
     */
    private function castBackedEnum(mixed $raw, string $enumClass): ?\BackedEnum
    {
        $value = strtolower(trim((string) $raw));
        foreach ($enumClass::cases() as $case) {
            if (strtolower((string) $case->value) === $value) {
                return $case;
            }
        }

        return null;
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

        $value      = trim((string) $raw);
        $repository = $this->em->getRepository($field->relationClass);
        $found      = $repository->findOneBy([$field->relationLookup => $value, 'tenant' => $tenant]);

        if ($found === null && $field->relationFallbackLookup !== null && str_contains($value, '@')) {
            $found = $repository->findOneBy([$field->relationFallbackLookup => $value, 'tenant' => $tenant]);
        }

        return $found;
    }
}
