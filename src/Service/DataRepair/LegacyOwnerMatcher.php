<?php

declare(strict_types=1);

namespace App\Service\DataRepair;

use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;

/**
 * Finds the User or Person a legacy free-text owner ("Max Muster",
 * "max.muster@firma.de", "Muster, Max") most likely means — inside the
 * record's own organisation, case- and whitespace-insensitive, exact only.
 *
 * A name that fits more than one account in the organisation gives no
 * suggestion: picking one of two "Max Muster" would be a guess. Users win
 * over Persons, because a User carries the login the owner role is for.
 *
 * Pure in-memory index, no database access — {@see LegacyOwnerReport} loads
 * the users and persons of the scoped organisations once.
 */
final class LegacyOwnerMatcher
{
    /** @var array<int, array<string, list<User>>> tenant object id → key → users */
    private array $users = [];

    /** @var array<int, array<string, list<Person>>> tenant object id → key → persons */
    private array $persons = [];

    /**
     * @param iterable<User>   $users
     * @param iterable<Person> $persons
     */
    public function __construct(iterable $users, iterable $persons)
    {
        foreach ($users as $user) {
            $tenant = $user->getTenant();
            if ($tenant === null || !$user->isActive()) {
                continue;
            }
            $keys = [$user->getEmail()];
            $first = trim((string) $user->getFirstName());
            $last = trim((string) $user->getLastName());
            if ($first !== '' && $last !== '') {
                array_push($keys, $first . ' ' . $last, $last . ' ' . $first, $last . ', ' . $first);
            }
            foreach (array_unique(array_filter(array_map(self::normalize(...), $keys))) as $key) {
                $this->users[spl_object_id($tenant)][$key][] = $user;
            }
        }
        foreach ($persons as $person) {
            $tenant = $person->getTenant();
            if ($tenant === null || $person->isActive() === false) {
                continue;
            }
            $keys = [$person->getFullName(), $person->getEmail()];
            foreach (array_unique(array_filter(array_map(self::normalize(...), $keys))) as $key) {
                $this->persons[spl_object_id($tenant)][$key][] = $person;
            }
        }
    }

    /**
     * The one User or Person the text names in that organisation, or null.
     */
    public function match(?string $legacy, ?Tenant $tenant): User|Person|null
    {
        $key = self::normalize($legacy);
        if ($key === '' || $tenant === null) {
            return null;
        }
        $tenantKey = spl_object_id($tenant);

        $users = $this->users[$tenantKey][$key] ?? [];
        if (count($users) === 1) {
            return $users[0];
        }
        if (count($users) > 1) {
            return null;
        }
        $persons = $this->persons[$tenantKey][$key] ?? [];

        return count($persons) === 1 ? $persons[0] : null;
    }

    /**
     * Lower case, single spaces, no surrounding blanks — the comparison key.
     */
    public static function normalize(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
