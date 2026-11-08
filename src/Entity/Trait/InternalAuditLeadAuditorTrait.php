<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Entity\Person;
use App\Entity\User;
use App\Service\OwnerResolver;
use App\Validator\Constraint\SameTenantOwner;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Lead auditor (Pattern A dual-state): User + Person + effective accessor.
 * Extracted from InternalAudit to keep the entity within its god-class budget.
 */
trait InternalAuditLeadAuditorTrait
{
    /**
     * Pattern A dual-state: preferred structured lead auditor
     * as an application User. Falls back to leadAuditorPerson.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'lead_auditor_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['audit:read', 'audit:write'])]
    #[SameTenantOwner]
    private ?User $leadAuditorUser = null;

    /**
     * Pattern A dual-state: preferred structured lead auditor
     * as a Stammdaten Person (external auditor without app login).
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'lead_auditor_person_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['audit:read', 'audit:write'])]
    #[SameTenantOwner]
    private ?Person $leadAuditorPerson = null;

    public function getLeadAuditorUser(): ?User
    {
        return $this->leadAuditorUser;
    }

    public function setLeadAuditorUser(?User $leadAuditorUser): static
    {
        $this->leadAuditorUser = $leadAuditorUser;
        return $this;
    }

    public function getLeadAuditorPerson(): ?Person
    {
        return $this->leadAuditorPerson;
    }

    public function setLeadAuditorPerson(?Person $leadAuditorPerson): static
    {
        $this->leadAuditorPerson = $leadAuditorPerson;
        return $this;
    }

    /**
     * Lead auditor's name: the User, else the Person. Templates and the API
     * (`leadAuditor`, read-only) read this instead of the raw fields.
     */
    #[Groups(['audit:read'])]
    #[SerializedName('leadAuditor')]
    public function getEffectiveLeadAuditorName(): ?string
    {
        return OwnerResolver::resolveEffective(
            $this->leadAuditorUser,
            $this->leadAuditorPerson,
        );
    }
}
