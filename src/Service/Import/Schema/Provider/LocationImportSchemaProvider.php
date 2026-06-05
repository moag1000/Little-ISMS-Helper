<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\Location;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see Location} (physical sites / security zones).
 *
 * Field set mirrors the user-editable fields exposed by {@see \App\Form\LocationType}:
 *   - single-select choice fields with a fixed value list → TYPE_ENUM (lowercased)
 *   - yes/no toggles → TYPE_BOOL
 *   - integer field → TYPE_INT
 *   - decimal field (NumberType) → TYPE_FLOAT
 *   - self-referencing FK (parentLocation) → TYPE_RELATION resolved by `name`
 *   - free-text / textarea → TYPE_STRING / TYPE_TEXT
 *
 * `name` is the unique upsert key (NotBlank on the entity). The LocationType form
 * carries no module-gated fields, so no per-field `module` is declared and the
 * {@see EntityImportSchema} module is null (Location is part of the always-on
 * core/locations surface).
 *
 * Excluded by design: id, tenant, createdAt/updatedAt timestamps, and any
 * computed/derived properties — only LocationType ->add() fields are mirrored.
 */
final class LocationImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'Location';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'Location',
            entityClass: Location::class,
            module: null,
            fields: [
                // ── Overview ────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'name',
                    setter: 'setName',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                    unique: true,
                ),
                new ImportFieldSpec(
                    name: 'locationType',
                    setter: 'setLocationType',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: [
                        'building',
                        'floor',
                        'room',
                        'area',
                        'datacenter',
                        'server_room',
                        'office',
                        'warehouse',
                        'gate',
                        'entrance',
                        'parking',
                        'outdoor',
                        'other',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'code',
                    setter: 'setCode',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'parentLocation',
                    setter: 'setParentLocation',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: Location::class,
                    relationLookup: 'name',
                ),
                new ImportFieldSpec(
                    name: 'description',
                    setter: 'setDescription',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),

                // ── Address ─────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'address',
                    setter: 'setAddress',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
                new ImportFieldSpec(
                    name: 'city',
                    setter: 'setCity',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'country',
                    setter: 'setCountry',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'postalCode',
                    setter: 'setPostalCode',
                    type: ImportFieldSpec::TYPE_STRING,
                ),

                // ── Security zone ───────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'securityLevel',
                    setter: 'setSecurityLevel',
                    type: ImportFieldSpec::TYPE_ENUM,
                    required: true,
                    enumValues: [
                        'public',
                        'restricted',
                        'controlled',
                        'secure',
                        'high_security',
                    ],
                ),

                // ── Access control ──────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'requiresBadgeAccess',
                    setter: 'setRequiresBadgeAccess',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'requiresEscort',
                    setter: 'setRequiresEscort',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'cameraMonitored',
                    setter: 'setCameraMonitored',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'accessControlSystem',
                    setter: 'setAccessControlSystem',
                    type: ImportFieldSpec::TYPE_STRING,
                ),

                // ── Environmental ───────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'capacity',
                    setter: 'setCapacity',
                    type: ImportFieldSpec::TYPE_INT,
                ),
                new ImportFieldSpec(
                    name: 'squareMeters',
                    setter: 'setSquareMeters',
                    type: ImportFieldSpec::TYPE_FLOAT,
                ),

                // ── Audit metadata ──────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'responsiblePerson',
                    setter: 'setResponsiblePerson',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'active',
                    setter: 'setActive',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),
                new ImportFieldSpec(
                    name: 'notes',
                    setter: 'setNotes',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
            ],
        );
    }
}
