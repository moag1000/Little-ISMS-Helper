<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Entity\Person;
use App\Validator\Constraint\SameTenantOwner;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Long-term governance owner of an incident. Extracted from Incident to
 * keep the entity within its god-class budget.
 */
trait IncidentResponsiblePersonTrait
{
    /**
     * Long-term governance owner of the
     * incident, distinct from the action-bound `assigned_to` ticket
     * assignee and the `reported_by_*` audit-trail. May be an external
     * Person without a system login (e.g. CISO consultant).
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'responsible_person_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['incident:read', 'incident:write'])]
    #[SameTenantOwner]
    private ?Person $responsiblePerson = null;

    public function getResponsiblePerson(): ?Person
    {
        return $this->responsiblePerson;
    }

    public function setResponsiblePerson(?Person $responsiblePerson): static
    {
        $this->responsiblePerson = $responsiblePerson;
        return $this;
    }

    /**
     * Effective responsible-person display: prefer the new
     * `responsiblePerson.fullName`, fall back to the legacy
     * `assignedTo` string. Returns null when neither is set.
     */
    public function getEffectiveResponsiblePersonName(): ?string
    {
        return $this->responsiblePerson?->getFullName()
            ?? $this->assignedTo;
    }
}
