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
 * Plan owner (Pattern A dual-state): User + Person + deputies + effective accessors. Extracted
 * from BusinessContinuityPlan to keep the entity within its god-class budget.
 */
trait BcPlanOwnerTrait
{
    /**
     * Pattern A dual-state: preferred structured owner. Falls back to string planOwner.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'plan_owner_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['bc_plan:read', 'bc_plan:write'])]
    #[SameTenantOwner]
    private ?User $planOwnerUser = null;

    public function getPlanOwnerUser(): ?User
    {
        return $this->planOwnerUser;
    }

    public function setPlanOwnerUser(?User $planOwnerUser): static
    {
        $this->planOwnerUser = $planOwnerUser;
        return $this;
    }

    /**
     * Person-based plan owner: for contacts without a system login.
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'plan_owner_person_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['bc_plan:read', 'bc_plan:write'])]
    #[SameTenantOwner]
    private ?Person $planOwnerPerson = null;

    public function getPlanOwnerPerson(): ?Person
    {
        return $this->planOwnerPerson;
    }

    public function setPlanOwnerPerson(?Person $planOwnerPerson): static
    {
        $this->planOwnerPerson = $planOwnerPerson;
        return $this;
    }

    /**
     * Deputies / Vertretung — n additional Persons sharing plan ownership.
     *
     * @var Collection<int, Person>
     */
    #[Groups(['bc_plan:read', 'bc_plan:write'])]
    #[ORM\ManyToMany(targetEntity: Person::class)]
    #[ORM\JoinTable(name: 'bc_plan_owner_deputy')]
    #[ORM\JoinColumn(name: 'bc_plan_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'person_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[SameTenantOwner]
    private Collection $planOwnerDeputyPersons;

    /** @return Collection<int, Person> */
    public function getPlanOwnerDeputyPersons(): Collection
    {
        return $this->planOwnerDeputyPersons;
    }

    public function addPlanOwnerDeputyPerson(Person $person): static
    {
        if (!$this->planOwnerDeputyPersons->contains($person)) {
            $this->planOwnerDeputyPersons->add($person);
        }
        return $this;
    }

    public function removePlanOwnerDeputyPerson(Person $person): static
    {
        $this->planOwnerDeputyPersons->removeElement($person);
        return $this;
    }

    /**
     * Effective planOwner: prefer planOwnerUser.fullName, then Person, fall back to legacy string.
     */
    public function getEffectivePlanOwner(): ?string
    {
        return OwnerResolver::resolveEffective(
            $this->planOwnerUser,
            $this->planOwnerPerson,
            $this->planOwner,
        );
    }

    /**
     * Full plan owner roster: primary + every deputy.
     *
     * @return list<string>
     */
    public function getAllPlanOwners(): array
    {
        return OwnerResolver::resolveAll(
            $this->planOwnerUser,
            $this->planOwnerPerson,
            $this->planOwner,
            $this->planOwnerDeputyPersons,
        );
    }
}
