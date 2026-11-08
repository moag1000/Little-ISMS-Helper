<?php

declare(strict_types=1);

namespace App\Service\Import\Schema\Provider;

use App\Entity\Person;
use App\Entity\User;
use App\Service\Import\Schema\ImportFieldSpec;

/**
 * The owner columns of an import: `<base>User` (e-mail), `<base>Ref` /
 * `<base>Person` (name) and the plain `<base>` column that takes a name or an
 * e-mail address as text — one definition for every import provider.
 *
 * A given user/person that does not resolve refuses the row (never silently
 * dropped). Both lookups are tenant-scoped by SchemaDrivenMapper::resolveRelation(),
 * so a user or person of another organisation never resolves. The text column
 * resolves to a User, else a Person, else records a new Person of the
 * organisation on commit ({@see \App\Service\Owner\OwnerTextResolver}).
 */
final class OwnerImportFields
{
    /**
     * @param string $base entity property prefix, e.g. 'responsiblePerson' or 'contactPerson'
     * @param bool   $required the record needs an owner: the text column is required unless user or person is given
     *
     * @return list<ImportFieldSpec> `<base>` (text), `<base>User` (by e-mail) and `<base>Ref` (by name, else e-mail)
     */
    public static function specs(string $base, bool $required = false): array
    {
        return [
            self::text($base, $base . 'User', $base . 'Ref', $required, $required ? [$base . 'User', $base . 'Ref'] : []),
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

    /**
     * The plain owner column (header e.g. "owner", "responsiblePerson"): a
     * name or e-mail address as text.
     *
     * @param list<string> $requiredUnlessAny
     */
    public static function text(string $name, string $userProperty, string $personProperty, bool $required = false, array $requiredUnlessAny = []): ImportFieldSpec
    {
        return new ImportFieldSpec(
            name: $name,
            setter: 'set' . ucfirst($userProperty),
            type: ImportFieldSpec::TYPE_OWNER,
            required: $required,
            requiredUnlessAny: $requiredUnlessAny,
            personSetter: 'set' . ucfirst($personProperty),
        );
    }
}
