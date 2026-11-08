<?php

declare(strict_types=1);

namespace App\Service\Responsibility;

use App\Entity\Person;
use App\Entity\User;

/**
 * Who is responsible for one record, as the record states it.
 *
 * Resolution order (see {@see ResponsibilityResolver}):
 *   0. record   — when set: whoever is responsible for that other record
 *   1. holders  — owner / assignee / approver set on the record
 *                 (a Person counts through its linked user account)
 *   2. deputies — only when no holder resolves to an active account
 *   3. roles    — users reaching one of these roles (role hierarchy)
 *   4. admins   — last resort, so a duty never reaches nobody
 *
 * Besides the one who acts, a duty may name people to keep informed
 * ({@see alsoInform()}): they see it in My Day and get the reminder mails
 * too, but resolving who acts never stops at them. Example: the ISB is told
 * about every statutory report, also when the incident has an owner.
 */
final readonly class Responsibility
{
    /**
     * @param list<User|Person> $holders
     * @param list<User|Person> $deputies
     * @param list<string>      $roles
     * @param object|null       $record whoever is responsible for this other record
     *                                  (an approval on a risk goes to the risk owner)
     * @param list<User|Person> $informed      people kept informed
     * @param list<string>      $informedRoles roles kept informed (held, not merely inherited by admins)
     */
    private function __construct(
        public array $holders = [],
        public array $deputies = [],
        public array $roles = [],
        public ?object $record = null,
        public array $informed = [],
        public array $informedRoles = [],
    ) {
    }

    public static function holders(User|Person|null ...$holders): self
    {
        return new self(self::compact($holders));
    }

    public static function roles(string ...$roles): self
    {
        return new self([], [], array_values($roles));
    }

    /** Whoever is responsible for that record (resolved through its deadline source). */
    public static function ofRecord(?object $record): self
    {
        return new self([], [], [], $record);
    }

    public static function nobody(): self
    {
        return new self();
    }

    public function withDeputies(User|Person|null ...$deputies): self
    {
        return new self($this->holders, [...$this->deputies, ...self::compact($deputies)], $this->roles, $this->record, $this->informed, $this->informedRoles);
    }

    public function orRoles(string ...$roles): self
    {
        return new self($this->holders, $this->deputies, array_values(array_unique([...$this->roles, ...$roles])), $this->record, $this->informed, $this->informedRoles);
    }

    /**
     * Also keep these informed: a role name (string) or a person. They do not
     * act on the duty, so they never replace the one who does.
     */
    public function alsoInform(User|Person|string|null ...$whom): self
    {
        $roles = array_values(array_filter($whom, 'is_string'));
        $people = self::compact(array_filter($whom, static fn ($w): bool => !is_string($w)));

        return new self(
            $this->holders,
            $this->deputies,
            $this->roles,
            $this->record,
            [...$this->informed, ...$people],
            array_values(array_unique([...$this->informedRoles, ...$roles])),
        );
    }

    /**
     * @param array<int|string, User|Person|null> $people
     * @return list<User|Person>
     */
    private static function compact(array $people): array
    {
        return array_values(array_filter($people, static fn ($p): bool => $p !== null));
    }
}
