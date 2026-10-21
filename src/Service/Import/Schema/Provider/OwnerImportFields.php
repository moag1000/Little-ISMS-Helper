<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\Person;
use App\Entity\User;
use App\Service\Import\Schema\ImportFieldSpec;

/**
 * The dual-state owner columns (structured User / Person next to the legacy
 * free-text column) as import fields — one definition for every import
 * provider of an entity with a `<base>User` / `<base>Ref` owner pair.
 *
 * A given user/person that does not resolve refuses the row (never silently
 * dropped, also when free text is present). Both lookups are tenant-scoped by SchemaDrivenMapper::resolveRelation(), so
 * a user or person of another organisation never resolves.
 */
final class OwnerImportFields
{
    /**
     * @param string $base entity property prefix, e.g. 'responsiblePerson' or 'contactPerson'
     *
     * @return list<ImportFieldSpec> `<base>User` (by e-mail) and `<base>Ref` (by name, else e-mail)
     */
    public static function specs(string $base): array
    {
        return [
            new ImportFieldSpec(
                name: $base . 'User',
                setter: 'set' . ucfirst($base) . 'User',
                type: ImportFieldSpec::TYPE_RELATION,
                relationClass: User::class,
                relationLookup: 'email',
                refuseIfUnresolved: true,
            ),
            new ImportFieldSpec(
                name: $base . 'Ref',
                setter: 'set' . ucfirst($base) . 'Ref',
                type: ImportFieldSpec::TYPE_RELATION,
                relationClass: Person::class,
                relationLookup: 'fullName',
                relationFallbackLookup: 'email',
                refuseIfUnresolved: true,
            ),
        ];
    }
}
