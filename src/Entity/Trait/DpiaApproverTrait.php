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
 * DPIA approver slot (Art. 35 GDPR sign-off): User + Person + deputies + effective accessors.
 * Extracted from DataProtectionImpactAssessment to keep the entity within its god-class budget.
 */
trait DpiaApproverTrait
{
    /**
     * Approver (e.g., Data Protection Officer, Management)
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $approver = null;

    /**
     * Tri-State Person slot: approver as Person master-data record.
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'approver_person_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Person $approverPerson = null;

    /**
     * Deputy Persons for the approver slot.
     *
     * @var Collection<int, Person>
     */
    #[ORM\ManyToMany(targetEntity: Person::class)]
    #[ORM\JoinTable(name: 'dpia_approver_deputy')]
    #[ORM\JoinColumn(name: 'dpia_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'person_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[SameTenantOwner]
    private Collection $approverDeputyPersons;

    public function getApprover(): ?User
    {
        return $this->approver;
    }

    public function setApprover(?User $user): static
    {
        $this->approver = $user;
        return $this;
    }

    public function getApproverPerson(): ?Person
    {
        return $this->approverPerson;
    }

    public function setApproverPerson(?Person $approverPerson): static
    {
        $this->approverPerson = $approverPerson;
        return $this;
    }

    /** @return Collection<int, Person> */
    public function getApproverDeputyPersons(): Collection
    {
        return $this->approverDeputyPersons;
    }

    public function addApproverDeputyPerson(Person $person): static
    {
        if (!$this->approverDeputyPersons->contains($person)) {
            $this->approverDeputyPersons->add($person);
        }
        return $this;
    }

    public function removeApproverDeputyPerson(Person $person): static
    {
        $this->approverDeputyPersons->removeElement($person);
        return $this;
    }

    /**
     * Effective approver: prefer approver (User), then approverPerson, then null.
     */
    public function getEffectiveApprover(): ?string
    {
        return OwnerResolver::resolveEffective(
            $this->approver,
            $this->approverPerson,
            null,
        );
    }

    /**
     * Full approver roster: primary + every deputy.
     *
     * @return list<string>
     */
    public function getAllApprovers(): array
    {
        return OwnerResolver::resolveAll(
            $this->approver,
            $this->approverPerson,
            null,
            $this->approverDeputyPersons,
        );
    }
}
