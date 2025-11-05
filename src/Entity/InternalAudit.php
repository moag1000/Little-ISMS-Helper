<?php

namespace App\Entity;

use App\Repository\InternalAuditRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InternalAuditRepository::class)]
class InternalAudit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private ?string $auditNumber = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $scope = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $objectives = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $plannedDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $actualDate = null;

    #[ORM\Column(length: 100)]
    private ?string $leadAuditor = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $auditTeam = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $auditedDepartments = null;

    #[ORM\Column(length: 50)]
    private ?string $status = 'planned';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $findings = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $nonConformities = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $observations = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $recommendations = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $conclusion = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $reportDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuditNumber(): ?string
    {
        return $this->auditNumber;
    }

    public function setAuditNumber(string $auditNumber): static
    {
        $this->auditNumber = $auditNumber;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function setScope(?string $scope): static
    {
        $this->scope = $scope;
        return $this;
    }

    public function getObjectives(): ?string
    {
        return $this->objectives;
    }

    public function setObjectives(?string $objectives): static
    {
        $this->objectives = $objectives;
        return $this;
    }

    public function getPlannedDate(): ?\DateTimeInterface
    {
        return $this->plannedDate;
    }

    public function setPlannedDate(\DateTimeInterface $plannedDate): static
    {
        $this->plannedDate = $plannedDate;
        return $this;
    }

    public function getActualDate(): ?\DateTimeInterface
    {
        return $this->actualDate;
    }

    public function setActualDate(?\DateTimeInterface $actualDate): static
    {
        $this->actualDate = $actualDate;
        return $this;
    }

    public function getLeadAuditor(): ?string
    {
        return $this->leadAuditor;
    }

    public function setLeadAuditor(string $leadAuditor): static
    {
        $this->leadAuditor = $leadAuditor;
        return $this;
    }

    public function getAuditTeam(): ?string
    {
        return $this->auditTeam;
    }

    public function setAuditTeam(?string $auditTeam): static
    {
        $this->auditTeam = $auditTeam;
        return $this;
    }

    public function getAuditedDepartments(): ?string
    {
        return $this->auditedDepartments;
    }

    public function setAuditedDepartments(?string $auditedDepartments): static
    {
        $this->auditedDepartments = $auditedDepartments;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getFindings(): ?string
    {
        return $this->findings;
    }

    public function setFindings(?string $findings): static
    {
        $this->findings = $findings;
        return $this;
    }

    public function getNonConformities(): ?string
    {
        return $this->nonConformities;
    }

    public function setNonConformities(?string $nonConformities): static
    {
        $this->nonConformities = $nonConformities;
        return $this;
    }

    public function getObservations(): ?string
    {
        return $this->observations;
    }

    public function setObservations(?string $observations): static
    {
        $this->observations = $observations;
        return $this;
    }

    public function getRecommendations(): ?string
    {
        return $this->recommendations;
    }

    public function setRecommendations(?string $recommendations): static
    {
        $this->recommendations = $recommendations;
        return $this;
    }

    public function getConclusion(): ?string
    {
        return $this->conclusion;
    }

    public function setConclusion(?string $conclusion): static
    {
        $this->conclusion = $conclusion;
        return $this;
    }

    public function getReportDate(): ?\DateTimeInterface
    {
        return $this->reportDate;
    }

    public function setReportDate(?\DateTimeInterface $reportDate): static
    {
        $this->reportDate = $reportDate;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
