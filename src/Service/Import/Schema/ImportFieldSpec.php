<?php

declare(strict_types=1);

namespace App\Service\Import\Schema;

/**
 * Declarative spec for one importable field of an entity.
 *
 * The schema-driven import (see {@see SchemaDrivenMapper}) reads a list of these
 * instead of hand-written per-entity mapper code. A field can be:
 *   - a scalar (string/int/float/bool/date/text)
 *   - a decimal (numeric cell → numeric string, for `?string` setters of DECIMAL columns)
 *   - an enum (validated against allowed values; with `enumClass` cast to that BackedEnum)
 *   - a json list (comma/semicolon-separated → array)
 *   - a relation (resolved by looking up a related entity by a human field)
 *   - an owner given as text: `setter` is the User slot, `personSetter` the
 *     Person slot; the text resolves via {@see \App\Service\Owner\OwnerTextResolver}
 *     (unknown names become a Person of the organisation on commit)
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
    public const TYPE_DECIMAL  = 'decimal';   // numeric string (Doctrine DECIMAL → ?string setter)
    public const TYPE_BOOL     = 'bool';
    public const TYPE_DATE     = 'date';
    public const TYPE_ENUM     = 'enum';
    public const TYPE_LIST     = 'list';      // delimited string → array<string>
    public const TYPE_RELATION = 'relation';  // lookup related entity by a field
    public const TYPE_OWNER    = 'owner';     // name or e-mail → User, else Person, else a new Person

    /** @var class-string<\BackedEnum>|null the setter's enum when type = enum (from $relationClass) */
    public readonly ?string $enumClass;

    /**
     * @param list<string> $enumValues   allowed values when type = enum
     * @param list<string> $aliases       alternative source-column header names
     * @param ?string      $relationFallbackLookup second lookup field tried when the primary one finds nothing
     *                                    (e.g. Person: fullName, then e-mail for an "@" value)
     * @param list<string> $requiredUnlessAny names of sibling fields; a `required` field stays empty
     *                                    without error when any of them is given (user OR person OR text)
     * @param bool         $refuseIfUnresolved a given, non-empty value that does not resolve (relation) refuses
     *                                    the row instead of silently dropping it (owner columns)
     * @param ?string      $relationClass target class: the related entity (type = relation) or, for type = enum,
     *                                    the BackedEnum the setter takes — the cell is then cast via its cases
     *                                    (case-insensitive) and an unknown value is a row error ({@see $enumClass})
     * @param ?string      $personSetter  Person-slot setter when type = owner (`setter` is the User slot)
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
        public readonly ?string $relationFallbackLookup = null,
        public readonly array $requiredUnlessAny = [],
        public readonly bool $refuseIfUnresolved = false,
        public readonly ?string $personSetter = null,
    ) {
        $this->enumClass = $type === self::TYPE_ENUM && $relationClass !== null && is_subclass_of($relationClass, \BackedEnum::class)
            ? $relationClass
            : null;
    }

    public function isModuleGated(): bool
    {
        return $this->module !== null;
    }
}
