<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Entity\Person;
use App\Entity\User;
use App\Service\OwnerResolver;
use App\Validator\Constraint\SameTenantOwner;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Process owner (Pattern A dual-state): User + Person + deputies + effective accessors. Extracted
 * from BusinessProcess to keep the entity within its god-class budget.
 */
trait BusinessProcessOwnerTrait
{
    /**
     * Pattern A dual-state: preferred structured owner. Falls back to string processOwner.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'process_owner_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[SameTenantOwner]
    private ?User $processOwnerUser = null;

    public function getProcessOwnerUser(): ?User
    {
        return $this->processOwnerUser;
    }

    public function setProcessOwnerUser(?User $processOwnerUser): static
    {
        $this->processOwnerUser = $processOwnerUser;
        return $this;
    }

    /**
     * Person-based primary owner: when the process owner has no system login
     * (external stakeholder, shared mailbox, contractor), use this slot
     * instead of `processOwnerUser`. Falls back to the legacy string when
     * neither is set.
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'process_owner_person_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[SameTenantOwner]
    private ?Person $processOwnerPerson = null;

    public function getProcessOwnerPerson(): ?Person
    {
        return $this->processOwnerPerson;
    }

    public function setProcessOwnerPerson(?Person $processOwnerPerson): static
    {
        $this->processOwnerPerson = $processOwnerPerson;
        return $this;
    }

    /**
     * Deputies / Vertretung — n additional Persons sharing ownership of this
     * process. ManyToMany via dedicated join table; cascade detach on Person
     * delete keeps the link table clean. UI should sort by Person.fullName
     * when displaying the roster.
     *
     * @var Collection<int, Person>
     */
    #[ORM\ManyToMany(targetEntity: Person::class)]
    #[ORM\JoinTable(name: 'business_process_owner_deputy')]
    #[ORM\JoinColumn(name: 'business_process_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'person_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $processOwnerDeputyPersons;

    /** @return Collection<int, Person> */
    public function getProcessOwnerDeputyPersons(): Collection
    {
        return $this->processOwnerDeputyPersons;
    }

    public function addProcessOwnerDeputyPerson(Person $person): static
    {
        if (!$this->processOwnerDeputyPersons->contains($person)) {
            $this->processOwnerDeputyPersons->add($person);
        }
        return $this;
    }

    public function removeProcessOwnerDeputyPerson(Person $person): static
    {
        $this->processOwnerDeputyPersons->removeElement($person);
        return $this;
    }

    /**
     * Effective processOwner: prefer processOwnerUser.fullName, fall back to Person, then legacy string.
     */
    public function getEffectiveProcessOwner(): ?string
    {
        return OwnerResolver::resolveEffective(
            $this->processOwnerUser,
            $this->processOwnerPerson,
            $this->processOwner,
        );
    }

    /**
     * Full owner roster: primary (Tri-State chain) followed by every deputy.
     * Returns a list of display names. Empty when no owner is assigned.
     *
     * @return list<string>
     */
    public function getAllProcessOwners(): array
    {
        return OwnerResolver::resolveAll(
            $this->processOwnerUser,
            $this->processOwnerPerson,
            $this->processOwner,
            $this->processOwnerDeputyPersons,
        );
    }
}
