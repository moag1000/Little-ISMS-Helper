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
 * Data Protection Officer slot (Art. 30/37-39 GDPR): User + Person + deputies + effective
 * accessors. Extracted from ProcessingActivity to keep the entity within its god-class budget.
 */
trait ProcessingActivityDpoTrait
{
    /**
     * Data Protection Officer notified about this activity
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[SameTenantOwner]
    private ?User $dataProtectionOfficer = null;

    /**
     * Tri-State Person slot: DPO as Person master-data record.
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'data_protection_officer_person_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[SameTenantOwner]
    private ?Person $dataProtectionOfficerPerson = null;

    /**
     * Deputy Persons for the DPO slot.
     *
     * @var Collection<int, Person>
     */
    #[ORM\ManyToMany(targetEntity: Person::class)]
    #[ORM\JoinTable(name: 'processing_activity_dpo_deputy')]
    #[ORM\JoinColumn(name: 'processing_activity_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'person_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[SameTenantOwner]
    private Collection $dataProtectionOfficerDeputyPersons;

    public function getDataProtectionOfficer(): ?User
    {
        return $this->dataProtectionOfficer;
    }

    public function setDataProtectionOfficer(?User $user): static
    {
        $this->dataProtectionOfficer = $user;
        return $this;
    }

    public function getDataProtectionOfficerPerson(): ?Person
    {
        return $this->dataProtectionOfficerPerson;
    }

    public function setDataProtectionOfficerPerson(?Person $dataProtectionOfficerPerson): static
    {
        $this->dataProtectionOfficerPerson = $dataProtectionOfficerPerson;
        return $this;
    }

    /** @return Collection<int, Person> */
    public function getDataProtectionOfficerDeputyPersons(): Collection
    {
        return $this->dataProtectionOfficerDeputyPersons;
    }

    public function addDataProtectionOfficerDeputyPerson(Person $person): static
    {
        if (!$this->dataProtectionOfficerDeputyPersons->contains($person)) {
            $this->dataProtectionOfficerDeputyPersons->add($person);
        }
        return $this;
    }

    public function removeDataProtectionOfficerDeputyPerson(Person $person): static
    {
        $this->dataProtectionOfficerDeputyPersons->removeElement($person);
        return $this;
    }

    /**
     * Effective DPO: prefer dataProtectionOfficer (User), then dataProtectionOfficerPerson, then null.
     */
    public function getEffectiveDataProtectionOfficer(): ?string
    {
        return OwnerResolver::resolveEffective(
            $this->dataProtectionOfficer,
            $this->dataProtectionOfficerPerson,
            null,
        );
    }

    /**
     * Full DPO roster: primary + every deputy.
     *
     * @return list<string>
     */
    public function getAllDataProtectionOfficers(): array
    {
        return OwnerResolver::resolveAll(
            $this->dataProtectionOfficer,
            $this->dataProtectionOfficerPerson,
            null,
            $this->dataProtectionOfficerDeputyPersons,
        );
    }
}
