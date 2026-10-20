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

/**
 * Lead auditor (Pattern A dual-state): legacy free text + User + Person + effective accessor.
 * Extracted from InternalAudit to keep the entity within its god-class budget.
 */
trait InternalAuditLeadAuditorTrait
{
    /**
     * Legacy free-text lead auditor name. P-15 DataReuse: kept read-only for
     * migration display once `leadAuditorUser` or `leadAuditorPerson` is set.
     * No longer NotBlank — the Pattern-A validator on the form enforces that
     * at least one of legacy/user/person is provided.
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['audit:read', 'audit:write'])]
    #[Assert\Length(max: 100, maxMessage: 'internal_audit.validation.lead_auditor_max_length')]
    private ?string $leadAuditor = null;

    /**
     * Pattern A dual-state (P-15 DataReuse): preferred structured lead auditor
     * as an application User. Falls back to leadAuditorPerson, then legacy
     * `leadAuditor` string.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'lead_auditor_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['audit:read', 'audit:write'])]
    #[SameTenantOwner]
    private ?User $leadAuditorUser = null;

    /**
     * Pattern A dual-state (P-15 DataReuse): preferred structured lead auditor
     * as a Stammdaten Person (external auditor without app login).
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'lead_auditor_person_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['audit:read', 'audit:write'])]
    #[SameTenantOwner]
    private ?Person $leadAuditorPerson = null;

    public function getLeadAuditor(): ?string
    {
        return $this->leadAuditor;
    }

    public function setLeadAuditor(?string $leadAuditor): static
    {
        $this->leadAuditor = $leadAuditor;
        return $this;
    }

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
     * P-15 DataReuse Tri-State resolver — prefer structured User name, then
     * Person, fall back to legacy `leadAuditor` free-text. Templates should
     * read this instead of accessing the raw fields directly.
     */
    public function getEffectiveLeadAuditorName(): ?string
    {
        return OwnerResolver::resolveEffective(
            $this->leadAuditorUser,
            $this->leadAuditorPerson,
            $this->leadAuditor,
        );
    }
}
