<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RiskIncidentLinkRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * RiskIncidentLink — Sprint 9B / F16
 *
 * Structured cross-link between a Risk register entry and an Incident report.
 * Replaces the implicit ManyToMany realizedRisks relation with an explicit
 * join-entity that carries linkType, notes, timestamp and the linking user.
 *
 * linkType values (enum-string):
 *   materialized      — the risk actually materialised (loss event confirmed)
 *   suspected         — incident may be an instance of this risk (under investigation)
 *   related           — thematic relation without confirmed causality
 *   mitigation_failed — a control/mitigation for this risk failed during the incident
 *
 * Unique constraint: each (risk, incident) pair may only be linked once.
 * Cascade-delete: removing either Risk or Incident removes the link row.
 */
#[ORM\Entity(repositoryClass: RiskIncidentLinkRepository::class)]
#[ORM\Table(name: 'risk_incident_link')]
#[ORM\UniqueConstraint(name: 'uniq_risk_incident', columns: ['risk_id', 'incident_id'])]
#[ORM\Index(name: 'idx_tenant_link_type', columns: ['tenant_id', 'link_type'])]
class RiskIncidentLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: Risk::class)]
    #[ORM\JoinColumn(name: 'risk_id', nullable: false, onDelete: 'CASCADE')]
    private ?Risk $risk = null;

    #[ORM\ManyToOne(targetEntity: Incident::class)]
    #[ORM\JoinColumn(name: 'incident_id', nullable: false, onDelete: 'CASCADE')]
    private ?Incident $incident = null;

    /**
     * Type of cross-link:
     *   materialized | suspected | related | mitigation_failed
     */
    #[ORM\Column(name: 'link_type', length: 32)]
    private string $linkType = 'related';

    #[ORM\Column(name: 'linked_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $linkedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'linked_by_id', nullable: true)]
    private ?User $linkedBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
        $this->linkedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getRisk(): ?Risk
    {
        return $this->risk;
    }

    public function setRisk(?Risk $risk): static
    {
        $this->risk = $risk;
        return $this;
    }

    public function getIncident(): ?Incident
    {
        return $this->incident;
    }

    public function setIncident(?Incident $incident): static
    {
        $this->incident = $incident;
        return $this;
    }

    public function getLinkType(): string
    {
        return $this->linkType;
    }

    public function setLinkType(string $linkType): static
    {
        $this->linkType = $linkType;
        return $this;
    }

    public function getLinkedAt(): DateTimeImmutable
    {
        return $this->linkedAt;
    }

    public function setLinkedAt(DateTimeImmutable $linkedAt): static
    {
        $this->linkedAt = $linkedAt;
        return $this;
    }

    public function getLinkedBy(): ?User
    {
        return $this->linkedBy;
    }

    public function setLinkedBy(?User $linkedBy): static
    {
        $this->linkedBy = $linkedBy;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    /**
     * Human-readable label for link type.
     */
    public function getLinkTypeLabel(): string
    {
        return match ($this->linkType) {
            'materialized'     => 'Materialized',
            'suspected'        => 'Suspected',
            'mitigation_failed'=> 'Mitigation Failed',
            default            => 'Related',
        };
    }
}
