<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Entity\Person;
use App\Entity\User;
use App\Service\OwnerResolver;
use App\Validator\Constraint\SameTenantOwner;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Responsible person (Pattern A dual-state): User + Person + deputies + effective accessors.
 * Extracted from Control to keep the entity within its god-class budget.
 */
trait ControlResponsiblePersonTrait
{
    /**
     * Pattern A dual-state: preferred structured owner. Falls back to string responsiblePerson.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'responsible_person_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['control:read', 'control:write'])]
    #[SameTenantOwner]
    private ?User $responsiblePersonUser = null;

    public function getResponsiblePersonUser(): ?User
    {
        return $this->responsiblePersonUser;
    }

    public function setResponsiblePersonUser(?User $responsiblePersonUser): static
    {
        $this->responsiblePersonUser = $responsiblePersonUser;
        return $this;
    }

    /**
     * Person-based responsible person: for contacts without a system login.
     * Renamed from `responsiblePersonContact` (awkward "Contact" suffix);
     * uses `responsiblePersonRef` to parallel the User-FK field naming.
     * DB column name `responsible_person_contact_id` is unchanged (avoids DDL).
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'responsible_person_contact_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['control:read', 'control:write'])]
    #[SameTenantOwner]
    private ?Person $responsiblePersonRef = null;

    public function getResponsiblePersonRef(): ?Person
    {
        return $this->responsiblePersonRef;
    }

    public function setResponsiblePersonRef(?Person $responsiblePersonRef): static
    {
        $this->responsiblePersonRef = $responsiblePersonRef;
        return $this;
    }

    /**
     * Deputies / Vertretung — n additional Persons sharing responsibility.
     *
     * @var Collection<int, Person>
     */
    #[Groups(['control:read', 'control:write'])]
    #[ORM\ManyToMany(targetEntity: Person::class)]
    #[ORM\JoinTable(name: 'control_responsible_deputy')]
    #[ORM\JoinColumn(name: 'control_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'person_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[SameTenantOwner]
    private Collection $responsibleDeputyPersons;

    /** @return Collection<int, Person> */
    public function getResponsibleDeputyPersons(): Collection
    {
        return $this->responsibleDeputyPersons;
    }

    public function addResponsibleDeputyPerson(Person $person): static
    {
        if (!$this->responsibleDeputyPersons->contains($person)) {
            $this->responsibleDeputyPersons->add($person);
        }
        return $this;
    }

    public function removeResponsibleDeputyPerson(Person $person): static
    {
        $this->responsibleDeputyPersons->removeElement($person);
        return $this;
    }

    /**
     * Effective responsiblePerson: prefer responsiblePersonUser.fullName,
     * then responsiblePersonRef (Person), fall back to legacy string.
     */
    public function getEffectiveResponsiblePerson(): ?string
    {
        return OwnerResolver::resolveEffective(
            $this->responsiblePersonUser,
            $this->responsiblePersonRef,
            $this->responsiblePerson,
        );
    }

    /**
     * Full responsible-person roster: primary + every deputy.
     *
     * @return list<string>
     */
    public function getAllResponsiblePersons(): array
    {
        return OwnerResolver::resolveAll(
            $this->responsiblePersonUser,
            $this->responsiblePersonRef,
            $this->responsiblePerson,
            $this->responsibleDeputyPersons,
        );
    }
}
