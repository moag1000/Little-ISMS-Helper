<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Entity\Person;
use App\Validator\Constraint\SameTenantOwner;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Audit team (Pattern A dual-state): legacy free text + typed Person roster. Extracted from
 * InternalAudit to keep the entity within its god-class budget.
 */
trait InternalAuditTeamTrait
{
    /**
     * Legacy free-text audit team list ("Names, comma-separated"). P-15
     * DataReuse: kept read-only for migration display once the typed
     * `auditTeamMembers` collection is populated.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['audit:read', 'audit:write'])]
    private ?string $auditTeam = null;

    /**
     * Pattern A dual-state (P-15 DataReuse): typed audit-team roster as a
     * Collection<Person>. Replaces the legacy comma-separated `auditTeam`
     * textarea.
     *
     * @var Collection<int, Person>
     */
    #[ORM\ManyToMany(targetEntity: Person::class)]
    #[ORM\JoinTable(name: 'internal_audit_team_member')]
    #[ORM\JoinColumn(name: 'internal_audit_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'person_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Groups(['audit:read', 'audit:write'])]
    #[SameTenantOwner]
    private ?Collection $auditTeamMembers = null;

    public function getAuditTeam(): ?string
    {
        return $this->auditTeam;
    }

    public function setAuditTeam(?string $auditTeam): static
    {
        $this->auditTeam = $auditTeam;
        return $this;
    }

    /** @return Collection<int, Person> */
    public function getAuditTeamMembers(): Collection
    {
        return $this->auditTeamMembers ??= new ArrayCollection();
    }

    public function addAuditTeamMember(Person $person): static
    {
        if (!$this->getAuditTeamMembers()->contains($person)) {
            $this->getAuditTeamMembers()->add($person);
        }
        return $this;
    }

    public function removeAuditTeamMember(Person $person): static
    {
        $this->getAuditTeamMembers()->removeElement($person);
        return $this;
    }
}
