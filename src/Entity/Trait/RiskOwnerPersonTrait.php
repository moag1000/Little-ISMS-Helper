<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use ApiPlatform\Metadata\ApiProperty;
use App\Entity\Person;
use App\Service\OwnerResolver;
use App\Validator\Constraint\SameTenantOwner;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Risk owner as Person (Pattern A dual-state) + deputies + effective-owner accessors. Extracted
 * from Risk to keep the entity within its god-class budget.
 */
trait RiskOwnerPersonTrait
{
    /**
     * Person-based primary risk owner: for persons without a system login.
     * Falls back to riskOwner (User) → Person → no legacy string (Risk had no
     * string field; getEffectiveRiskOwner falls back to null when both are unset).
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'risk_owner_person_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['risk:read', 'risk:write'])]
    #[ApiProperty(security: "is_granted('ROLE_MANAGER')")] // F7: PII — mirror FieldVisibilityResolver Risk.ownerPerson
    #[SameTenantOwner]
    private ?Person $riskOwnerPerson = null;

    public function getRiskOwnerPerson(): ?Person
    {
        return $this->riskOwnerPerson;
    }

    public function setRiskOwnerPerson(?Person $riskOwnerPerson): static
    {
        $this->riskOwnerPerson = $riskOwnerPerson;
        return $this;
    }

    /**
     * Deputies / Vertretung — n additional Persons sharing ownership of this risk.
     *
     * @var Collection<int, Person>
     */
    #[Groups(['risk:read', 'risk:write'])]
    #[ApiProperty(security: "is_granted('ROLE_MANAGER')")] // F7: PII — mirror FieldVisibilityResolver Risk.ownerDeputies
    #[ORM\ManyToMany(targetEntity: Person::class)]
    #[ORM\JoinTable(name: 'risk_owner_deputy')]
    #[ORM\JoinColumn(name: 'risk_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'person_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[SameTenantOwner]
    private Collection $riskOwnerDeputyPersons;

    /** @return Collection<int, Person> */
    public function getRiskOwnerDeputyPersons(): Collection
    {
        return $this->riskOwnerDeputyPersons;
    }

    public function addRiskOwnerDeputyPerson(Person $person): static
    {
        if (!$this->riskOwnerDeputyPersons->contains($person)) {
            $this->riskOwnerDeputyPersons->add($person);
        }
        return $this;
    }

    public function removeRiskOwnerDeputyPerson(Person $person): static
    {
        $this->riskOwnerDeputyPersons->removeElement($person);
        return $this;
    }

    /**
     * Effective risk owner: prefer riskOwner (User), then riskOwnerPerson, then null.
     * Note: Risk has no legacy string field — the chain stops at Person.
     */
    public function getEffectiveRiskOwner(): ?string
    {
        return OwnerResolver::resolveEffective(
            $this->riskOwner,
            $this->riskOwnerPerson,
            null,
        );
    }

    /**
     * Full risk owner roster: primary + every deputy.
     *
     * @return list<string>
     */
    public function getAllRiskOwners(): array
    {
        return OwnerResolver::resolveAll(
            $this->riskOwner,
            $this->riskOwnerPerson,
            null,
            $this->riskOwnerDeputyPersons,
        );
    }
}
