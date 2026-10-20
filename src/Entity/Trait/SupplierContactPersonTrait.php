<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Entity\Person;
use App\Entity\User;
use App\Service\OwnerResolver;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use App\Validator\Constraint\SameTenantOwner;

/**
 * Supplier contact person: legacy free text + Pattern A dual-state owner cluster
 * (User FK + Person FK + deputies M2M + effective accessor).
 * Extracted from Supplier to keep the entity within its god-class budget.
 */
trait SupplierContactPersonTrait
{
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?string $contactPerson = null;

    /**
     * Pattern A dual-state: preferred structured contact (system user).
     * Falls back to the legacy string $contactPerson.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'contact_person_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['supplier:read', 'supplier:write'])]
    #[SameTenantOwner]
    private ?User $contactPersonUser = null;

    /**
     * Person-based contact: for contacts without a system login.
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'contact_person_ref_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['supplier:read', 'supplier:write'])]
    #[SameTenantOwner]
    private ?Person $contactPersonRef = null;

    /**
     * Deputies / Vertretung — n additional Persons sharing the contact role.
     *
     * @var Collection<int, Person>
     */
    #[Groups(['supplier:read', 'supplier:write'])]
    #[ORM\ManyToMany(targetEntity: Person::class)]
    #[ORM\JoinTable(name: 'supplier_contact_deputy')]
    #[ORM\JoinColumn(name: 'supplier_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'person_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[SameTenantOwner]
    private Collection $contactDeputyPersons;

    private function initContactDeputyPersons(): void
    {
        $this->contactDeputyPersons = new ArrayCollection();
    }

    public function getContactPerson(): ?string
    {
        return $this->contactPerson;
    }

    public function setContactPerson(?string $contactPerson): static
    {
        $this->contactPerson = $contactPerson;
        return $this;
    }

    public function getContactPersonUser(): ?User
    {
        return $this->contactPersonUser;
    }

    public function setContactPersonUser(?User $contactPersonUser): static
    {
        $this->contactPersonUser = $contactPersonUser;
        return $this;
    }

    public function getContactPersonRef(): ?Person
    {
        return $this->contactPersonRef;
    }

    public function setContactPersonRef(?Person $contactPersonRef): static
    {
        $this->contactPersonRef = $contactPersonRef;
        return $this;
    }

    /** @return Collection<int, Person> */
    public function getContactDeputyPersons(): Collection
    {
        return $this->contactDeputyPersons;
    }

    public function addContactDeputyPerson(Person $person): static
    {
        if (!$this->contactDeputyPersons->contains($person)) {
            $this->contactDeputyPersons->add($person);
        }
        return $this;
    }

    public function removeContactDeputyPerson(Person $person): static
    {
        $this->contactDeputyPersons->removeElement($person);
        return $this;
    }

    /**
     * Effective contact person: prefer contactPersonUser.fullName,
     * then contactPersonRef (Person), fall back to legacy string.
     */
    public function getEffectiveContactPerson(): ?string
    {
        return OwnerResolver::resolveEffective(
            $this->contactPersonUser,
            $this->contactPersonRef,
            $this->contactPerson,
        );
    }

    /**
     * Full contact-person roster: primary + every deputy.
     *
     * @return list<string>
     */
    public function getAllContactPersons(): array
    {
        return OwnerResolver::resolveAll(
            $this->contactPersonUser,
            $this->contactPersonRef,
            $this->contactPerson,
            $this->contactDeputyPersons,
        );
    }
}
