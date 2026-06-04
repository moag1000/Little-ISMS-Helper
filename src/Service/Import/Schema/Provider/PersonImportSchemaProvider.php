<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\Person;
use App\Entity\User;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportFieldSpec;
use App\Service\Import\Schema\ImportSchemaProviderInterface;

/**
 * Bulk-import schema for {@see Person} (central master-data record for
 * employees, contractors, visitors, vendors, auditors, …).
 *
 * The entity is not gated behind any optional compliance module, so the
 * {@see EntityImportSchema} carries `module: null` and the source
 * {@see \App\Form\PersonType} contains no per-field `isModuleActive()` gates.
 *
 * Field set mirrors every user-editable field exposed by PersonType:
 *   - free-text inputs → TYPE_STRING
 *   - textarea → TYPE_TEXT
 *   - single-select choice with a fixed value list → TYPE_ENUM (lowercased)
 *   - yes/no toggle → TYPE_BOOL
 *   - DateType single_text → TYPE_DATE
 *   - master-data FK (linkedUser → User) → TYPE_RELATION resolved by email
 *
 * `email` is the unique upsert key. `fullName` (NotBlank on the entity) is
 * flagged required.
 *
 * Excluded by design: id, tenant, createdAt/updatedAt timestamps,
 * computed accessors (displayName, hasValidAccess), and the inverse
 * One-To-Many `accessLogs` collection (no human lookup).
 */
final class PersonImportSchemaProvider implements ImportSchemaProviderInterface
{
    public function supports(string $entityType): bool
    {
        return $entityType === 'Person';
    }

    public function getSchema(): EntityImportSchema
    {
        return new EntityImportSchema(
            entityType: 'Person',
            entityClass: Person::class,
            module: null,
            fields: [
                // ── Identity ────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'fullName',
                    setter: 'setFullName',
                    type: ImportFieldSpec::TYPE_STRING,
                    required: true,
                ),
                new ImportFieldSpec(
                    name: 'personType',
                    setter: 'setPersonType',
                    type: ImportFieldSpec::TYPE_ENUM,
                    enumValues: [
                        'employee',
                        'contractor',
                        'visitor',
                        'vendor',
                        'auditor',
                        'consultant',
                        'other',
                    ],
                ),
                new ImportFieldSpec(
                    name: 'badgeId',
                    setter: 'setBadgeId',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'active',
                    setter: 'setActive',
                    type: ImportFieldSpec::TYPE_BOOL,
                ),

                // ── Contact ─────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'email',
                    setter: 'setEmail',
                    type: ImportFieldSpec::TYPE_STRING,
                    unique: true,
                ),
                new ImportFieldSpec(
                    name: 'phone',
                    setter: 'setPhone',
                    type: ImportFieldSpec::TYPE_STRING,
                ),

                // ── Organization ────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'company',
                    setter: 'setCompany',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'department',
                    setter: 'setDepartment',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'jobTitle',
                    setter: 'setJobTitle',
                    type: ImportFieldSpec::TYPE_STRING,
                ),
                new ImportFieldSpec(
                    name: 'linkedUser',
                    setter: 'setLinkedUser',
                    type: ImportFieldSpec::TYPE_RELATION,
                    relationClass: User::class,
                    relationLookup: 'email',
                ),

                // ── Access validity ─────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'accessValidFrom',
                    setter: 'setAccessValidFrom',
                    type: ImportFieldSpec::TYPE_DATE,
                ),
                new ImportFieldSpec(
                    name: 'accessValidUntil',
                    setter: 'setAccessValidUntil',
                    type: ImportFieldSpec::TYPE_DATE,
                ),

                // ── Notes ───────────────────────────────────────────────────────
                new ImportFieldSpec(
                    name: 'notes',
                    setter: 'setNotes',
                    type: ImportFieldSpec::TYPE_TEXT,
                ),
            ],
        );
    }
}
