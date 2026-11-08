<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Person;
use App\Entity\User;

/**
 * Centralizes owner resolution: User → Person. Used by every entity's
 * `getEffective*` accessor + the `getAll*Owners` aggregator instead of
 * duplicating the chain in 14+ entities. (The legacy free-text owner columns
 * were dropped in 2026-11; text given on import or restore becomes a User or
 * Person via {@see \App\Service\Owner\OwnerTextResolver}.)
 *
 * Pure function shape — no DB or service dependencies. Can be `new`-instantiated
 * inside an entity method without DI overhead.
 */
final class OwnerResolver
{
    /**
     * Returns the display name of the highest-priority owner that is set.
     */
    public static function resolveEffective(?User $user, ?Person $person): ?string
    {
        return $user?->getFullName()
            ?? $person?->getFullName();
    }

    /**
     * Returns the full owner roster as a list of display names: primary first
     * (resolved via the same User→Person chain), then each deputy
     * Person in the order provided. Empty/null entries are skipped.
     *
     * @param iterable<Person> $deputies
     * @return list<string>
     */
    public static function resolveAll(?User $primaryUser, ?Person $primaryPerson, iterable $deputies): array
    {
        $names = [];
        $primary = self::resolveEffective($primaryUser, $primaryPerson);
        if ($primary !== null && $primary !== '') {
            $names[] = $primary;
        }
        foreach ($deputies as $deputy) {
            $name = $deputy->getFullName();
            if ($name !== null && $name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Who a reminder for this owner slot goes to: the User, else the Person's
     * linked account or e-mail address. A Person without either reaches nobody.
     */
    public static function recipient(?User $user, ?Person $person): User|string|null
    {
        if ($user !== null) {
            return $user;
        }
        if ($person?->getLinkedUser() !== null) {
            return $person->getLinkedUser();
        }
        $address = trim((string) $person?->getEmail());

        return $address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
    }
}
