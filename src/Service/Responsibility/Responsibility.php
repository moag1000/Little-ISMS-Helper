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
 *   3. roles    — users reaching one of these roles (role hierarchy);
 *                 {@see thenRoles()} names a wider circle asked only when
 *                 nobody holds the first (risk specialists, then any manager)
 *   4. admins   — last resort, so a duty never reaches nobody — except for a
 *                 duty only that role may discharge ({@see withoutAdminFallback()}:
 *                 a board decision does not go to admins, who would get a 403)
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
     * @param list<string>      $fallbackRoles asked only when nobody holds one of $roles
     * @param bool              $adminFallback false: nobody rather than admins who may not act
     */
    private function __construct(
        public array $holders = [],
        public array $deputies = [],
        public array $roles = [],
        public ?object $record = null,
        public array $informed = [],
        public array $informedRoles = [],
        public array $fallbackRoles = [],
        public bool $adminFallback = true,
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
        return $this->with(deputies: [...$this->deputies, ...self::compact($deputies)]);
    }

    public function orRoles(string ...$roles): self
    {
        return $this->with(roles: array_values(array_unique([...$this->roles, ...$roles])));
    }

    /**
     * A wider circle asked only when nobody holds one of the roles: the risk
     * manager decides an acceptance, any manager when the organisation has none.
     */
    public function thenRoles(string ...$roles): self
    {
        return $this->with(fallbackRoles: array_values(array_unique([...$this->fallbackRoles, ...$roles])));
    }

    /**
     * Only the named people and roles may discharge the duty (a board decision,
     * ISO 27001 5.1): when none resolves, nobody acts — admins are not asked,
     * a separate duty ("Keine Leitung zugewiesen") tells them to assign the role.
     */
    public function withoutAdminFallback(): self
    {
        return $this->with(adminFallback: false);
    }

    /**
     * Also keep these informed: a role name (string) or a person. They do not
     * act on the duty, so they never replace the one who does.
     */
    public function alsoInform(User|Person|string|null ...$whom): self
    {
        $roles = array_values(array_filter($whom, 'is_string'));
        $people = self::compact(array_filter($whom, static fn ($w): bool => !is_string($w)));

        return $this->with(
            informed: [...$this->informed, ...$people],
            informedRoles: array_values(array_unique([...$this->informedRoles, ...$roles])),
        );
    }

    /**
     * @param list<User|Person>|null $deputies
     * @param list<string>|null      $roles
     * @param list<User|Person>|null $informed
     * @param list<string>|null      $informedRoles
     * @param list<string>|null      $fallbackRoles
     */
    private function with(
        ?array $deputies = null,
        ?array $roles = null,
        ?array $informed = null,
        ?array $informedRoles = null,
        ?array $fallbackRoles = null,
        ?bool $adminFallback = null,
    ): self {
        return new self(
            $this->holders,
            $deputies ?? $this->deputies,
            $roles ?? $this->roles,
            $this->record,
            $informed ?? $this->informed,
            $informedRoles ?? $this->informedRoles,
            $fallbackRoles ?? $this->fallbackRoles,
            $adminFallback ?? $this->adminFallback,
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
