<?php

declare(strict_types=1);

namespace App\Service\Owner;

use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a name or e-mail address written as text ("Max Muster",
 * "max.muster@firma.de", "Muster, Max") into the User or Person it means in one
 * organisation — the one way an owner given as text gets onto a record since the
 * free-text owner columns are gone (spreadsheet import, GSTOOL import, restore of
 * an organisation backup written before the columns were dropped).
 *
 * Order: exactly one active User of the organisation (e-mail or name, see
 * {@see OwnerNameMatcher}), else exactly one active Person, else a Person of
 * that name that already exists, else a new Person (type "other"). The text is
 * never dropped and never guessed between two accounts.
 *
 * {@see find()} only reads — used by the import preview. {@see resolve()} may
 * create the Person: it is persisted, not flushed, and reused for the same text
 * until the caller flushes, so ten rows naming "Max Muster" give one Person.
 * Works after `EntityManager::clear()` (import batches): cached rows are
 * re-attached by id.
 */
class OwnerTextResolver
{
    /** @var array<string, OwnerNameMatcher> tenant key → matcher over the organisation's users and persons */
    private array $matchers = [];

    /** @var array<string, array<string, Person>> tenant key → normalised name → Person created or found in this run */
    private array $persons = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The existing User or Person the text names in that organisation — no writes.
     */
    public function find(?Tenant $tenant, ?string $text): User|Person|null
    {
        $key = OwnerNameMatcher::normalize($text);
        if ($key === '' || $tenant === null) {
            return null;
        }

        $matched = $this->matcher($tenant)->match($text, $tenant);
        if ($matched !== null) {
            return $this->managed($matched);
        }

        return $this->knownPerson($tenant, $key) ?? $this->personByName($tenant, $key);
    }

    /**
     * Like {@see find()}, but records an unknown name as a new Person of the
     * organisation. Null only for empty text or a record without organisation.
     */
    public function resolve(?Tenant $tenant, ?string $text): User|Person|null
    {
        $found = $this->find($tenant, $text);
        if ($found !== null || $tenant === null) {
            return $found;
        }
        $name = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $text)), 0, 255);
        if ($name === '') {
            return null;
        }

        $person = (new Person())
            ->setFullName($name)
            ->setPersonType('other')
            ->setTenant($tenant);
        if (filter_var($name, FILTER_VALIDATE_EMAIL) !== false) {
            $person->setEmail($name);
        }
        $this->entityManager->persist($person);
        $this->persons[$this->tenantKey($tenant)][OwnerNameMatcher::normalize($name)] = $person;

        return $person;
    }

    /**
     * Resolves the text and sets it on the record's User or Person slot, unless
     * one of the two is already filled. Returns what was set, or null.
     */
    public function assign(object $record, string $userProperty, string $personProperty, ?Tenant $tenant, ?string $text): User|Person|null
    {
        $getUser = 'get' . ucfirst($userProperty);
        $getPerson = 'get' . ucfirst($personProperty);
        if ($record->$getUser() !== null || $record->$getPerson() !== null) {
            return null;
        }
        $target = $this->resolve($tenant, $text);
        if ($target === null) {
            return null;
        }
        $setter = 'set' . ucfirst($target instanceof User ? $userProperty : $personProperty);
        $record->$setter($target);

        return $target;
    }

    private function matcher(Tenant $tenant): OwnerNameMatcher
    {
        $key = $this->tenantKey($tenant);
        if (!isset($this->matchers[$key])) {
            $users = $this->entityManager->createQueryBuilder()
                ->select('u')->from(User::class, 'u')
                ->where('u.tenant = :tenant')->setParameter('tenant', $tenant)
                ->getQuery()->getResult();
            $persons = $this->entityManager->createQueryBuilder()
                ->select('p')->from(Person::class, 'p')
                ->where('p.tenant = :tenant')->setParameter('tenant', $tenant)
                ->getQuery()->getResult();
            $this->matchers[$key] = new OwnerNameMatcher($users, $persons);
        }

        return $this->matchers[$key];
    }

    private function knownPerson(Tenant $tenant, string $key): ?Person
    {
        $person = $this->persons[$this->tenantKey($tenant)][$key] ?? null;
        if ($person === null) {
            return null;
        }
        if ($person->getId() === null && !$this->entityManager->contains($person)) {
            // created, then cleared before a flush — gone
            unset($this->persons[$this->tenantKey($tenant)][$key]);

            return null;
        }

        return $this->managed($person);
    }

    private function personByName(Tenant $tenant, string $key): ?Person
    {
        $person = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Person::class, 'p')
            ->where('p.tenant = :tenant')
            ->andWhere('LOWER(p.fullName) = :name')
            ->setParameter('tenant', $tenant)
            ->setParameter('name', $key)
            ->orderBy('p.id', \SortDirection::Ascending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if ($person instanceof Person) {
            $this->persons[$this->tenantKey($tenant)][$key] = $person;

            return $person;
        }

        return null;
    }

    /**
     * @template T of User|Person
     * @param T $entity
     * @return T
     */
    private function managed(User|Person $entity): User|Person
    {
        if ($this->entityManager->contains($entity) || $entity->getId() === null) {
            return $entity;
        }

        return $this->entityManager->getReference($entity::class, $entity->getId());
    }

    private function tenantKey(Tenant $tenant): string
    {
        return $tenant->getId() !== null ? 'id:' . $tenant->getId() : 'obj:' . spl_object_id($tenant);
    }
}
