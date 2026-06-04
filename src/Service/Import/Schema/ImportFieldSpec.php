<?php

declare(strict_types=1);

namespace App\Service\Import\Schema;

/**
 * Declarative spec for one importable field of an entity.
 *
 * The schema-driven import (see {@see SchemaDrivenMapper}) reads a list of these
 * instead of hand-written per-entity mapper code. A field can be:
 *   - a scalar (string/int/float/bool/date/text)
 *   - an enum (validated against allowed values)
 *   - a json list (comma/semicolon-separated → array)
 *   - a relation (resolved by looking up a related entity by a human field)
 *
 * `module` gates the field: when set, the field is only OFFERED and IMPORTED
 * when that module is active for the tenant — so a tenant without the DORA
 * module never sees DORA columns in the sample/mapping, and any such column in
 * an uploaded file is ignored rather than written.
 */
final class ImportFieldSpec
{
    public const TYPE_STRING   = 'string';
    public const TYPE_TEXT     = 'text';
    public const TYPE_INT      = 'int';
    public const TYPE_FLOAT    = 'float';
    public const TYPE_BOOL     = 'bool';
    public const TYPE_DATE     = 'date';
    public const TYPE_ENUM     = 'enum';
    public const TYPE_LIST     = 'list';      // delimited string → array<string>
    public const TYPE_RELATION = 'relation';  // lookup related entity by a field

    /**
     * @param list<string> $enumValues   allowed values when type = enum
     * @param list<string> $aliases       alternative source-column header names
     */
    public function __construct(
        public readonly string $name,
        public readonly string $setter,
        public readonly string $type = self::TYPE_STRING,
        public readonly bool $required = false,
        public readonly ?string $module = null,
        public readonly array $enumValues = [],
        public readonly ?string $relationClass = null,
        public readonly ?string $relationLookup = null,
        public readonly array $aliases = [],
        public readonly bool $unique = false,
        public readonly ?string $label = null,
    ) {
    }

    public function isModuleGated(): bool
    {
        return $this->module !== null;
    }
}
