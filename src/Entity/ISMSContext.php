<?php

namespace App\Entity;

use App\Repository\ISMSContextRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ISMSContextRepository::class)]
class ISMSContext
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $organizationName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $ismsScope = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $scopeExclusions = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $externalIssues = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $internalIssues = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $interestedParties = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $interestedPartiesRequirements = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $legalRequirements = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $regulatoryRequirements = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contractualObligations = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $ismsPolicy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rolesAndResponsibilities = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastReviewDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $nextReviewDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganizationName(): ?string
    {
        return $this->organizationName;
    }

    public function setOrganizationName(string $organizationName): static
    {
        $this->organizationName = $organizationName;
        return $this;
    }

    public function getIsmsScope(): ?string
    {
        return $this->ismsScope;
    }

    public function setIsmsScope(?string $ismsScope): static
    {
        $this->ismsScope = $ismsScope;
        return $this;
    }

    public function getScopeExclusions(): ?string
    {
        return $this->scopeExclusions;
    }

    public function setScopeExclusions(?string $scopeExclusions): static
    {
        $this->scopeExclusions = $scopeExclusions;
        return $this;
    }

    public function getExternalIssues(): ?string
    {
        return $this->externalIssues;
    }

    public function setExternalIssues(?string $externalIssues): static
    {
        $this->externalIssues = $externalIssues;
        return $this;
    }

    public function getInternalIssues(): ?string
    {
        return $this->internalIssues;
    }

    public function setInternalIssues(?string $internalIssues): static
    {
        $this->internalIssues = $internalIssues;
        return $this;
    }

    public function getInterestedParties(): ?string
    {
        return $this->interestedParties;
    }

    public function setInterestedParties(?string $interestedParties): static
    {
        $this->interestedParties = $interestedParties;
        return $this;
    }

    public function getInterestedPartiesRequirements(): ?string
    {
        return $this->interestedPartiesRequirements;
    }

    public function setInterestedPartiesRequirements(?string $interestedPartiesRequirements): static
    {
        $this->interestedPartiesRequirements = $interestedPartiesRequirements;
        return $this;
    }

    public function getLegalRequirements(): ?string
    {
        return $this->legalRequirements;
    }

    public function setLegalRequirements(?string $legalRequirements): static
    {
        $this->legalRequirements = $legalRequirements;
        return $this;
    }

    public function getRegulatoryRequirements(): ?string
    {
        return $this->regulatoryRequirements;
    }

    public function setRegulatoryRequirements(?string $regulatoryRequirements): static
    {
        $this->regulatoryRequirements = $regulatoryRequirements;
        return $this;
    }

    public function getContractualObligations(): ?string
    {
        return $this->contractualObligations;
    }

    public function setContractualObligations(?string $contractualObligations): static
    {
        $this->contractualObligations = $contractualObligations;
        return $this;
    }

    public function getIsmsPolicy(): ?string
    {
        return $this->ismsPolicy;
    }

    public function setIsmsPolicy(?string $ismsPolicy): static
    {
        $this->ismsPolicy = $ismsPolicy;
        return $this;
    }

    public function getRolesAndResponsibilities(): ?string
    {
        return $this->rolesAndResponsibilities;
    }

    public function setRolesAndResponsibilities(?string $rolesAndResponsibilities): static
    {
        $this->rolesAndResponsibilities = $rolesAndResponsibilities;
        return $this;
    }

    public function getLastReviewDate(): ?\DateTimeInterface
    {
        return $this->lastReviewDate;
    }

    public function setLastReviewDate(?\DateTimeInterface $lastReviewDate): static
    {
        $this->lastReviewDate = $lastReviewDate;
        return $this;
    }

    public function getNextReviewDate(): ?\DateTimeInterface
    {
        return $this->nextReviewDate;
    }

    public function setNextReviewDate(?\DateTimeInterface $nextReviewDate): static
    {
        $this->nextReviewDate = $nextReviewDate;
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
