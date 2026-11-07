<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Person;
use App\Entity\User;

/**
 * Centralizes Tri-State Person ownership resolution: User → Person → legacy
 * string. Used by every entity's `getEffective*` accessor + the new
 * `getAll*Owners` aggregator instead of duplicating the chain in 14+ entities.
 *
 * Pure function shape — no DB or service dependencies. Can be `new`-instantiated
 * inside an entity method without DI overhead.
 */
final class OwnerResolver
{
    /**
     * Returns the display name of the highest-priority owner that is set.
     */
    public static function resolveEffective(?User $user, ?Person $person, ?string $legacy): ?string
    {
        return $user?->getFullName()
            ?? $person?->getFullName()
            ?? $legacy;
    }

    /**
     * Returns the full owner roster as a list of display names: primary first
     * (resolved via the same User→Person→legacy chain), then each deputy
     * Person in the order provided. Empty/null entries are skipped.
     *
     * @param iterable<Person> $deputies
     * @return list<string>
     */
    public static function resolveAll(?User $primaryUser, ?Person $primaryPerson, ?string $primaryLegacy, iterable $deputies): array
    {
        $names = [];
        $primary = self::resolveEffective($primaryUser, $primaryPerson, $primaryLegacy);
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
     * linked account or e-mail address, else the legacy text when it is an
     * e-mail address. A plain name ("Max Muster") reaches nobody and gives null
     * — the admin page "Freitext-Verantwortliche" resolves those.
     */
    public static function recipient(?User $user, ?Person $person, ?string $legacy): User|string|null
    {
        if ($user !== null) {
            return $user;
        }
        if ($person?->getLinkedUser() !== null) {
            return $person->getLinkedUser();
        }
        foreach ([$person?->getEmail(), $legacy] as $candidate) {
            $address = trim((string) $candidate);
            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                return $address;
            }
        }

        return null;
    }
}
