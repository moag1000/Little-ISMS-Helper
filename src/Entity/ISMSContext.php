<?php

namespace App\Entity;

use App\Entity\Tenant;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Repository\ISMSContextRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Entity(repositoryClass: ISMSContextRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_ADMIN')"),
        new Put(security: "is_granted('ROLE_ADMIN')"),
        new Patch(security: "is_granted('ROLE_ADMIN')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['isms_context:read']],
    denormalizationContext: ['groups' => ['isms_context:write']]
)]
class ISMSContext
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['isms_context:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['isms_context:read', 'isms_context:write'])]
    private ?string $organizationName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['isms_context:read', 'isms_context:write'])]
    private ?string $ismsScope = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['isms_context:read', 'isms_context:write'])]
    private ?string $scopeExclusions = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['isms_context:read', 'isms_context:write'])]
    private ?string $externalIssues = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['isms_context:read', 'isms_context:write'])]
    private ?string $internalIssues = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['isms_context:read', 'isms_context:write'])]
    private ?string $interestedParties = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['isms_context:read', 'isms_context:write'])]
    private ?string $interestedPartiesRequirements = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['isms_context:read', 'isms_context:write'])]
    private ?string $legalRequirements = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['isms_context:read', 'isms_context:write'])]
    private ?string $regulatoryRequirements = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['isms_context:read', 'isms_context:write'])]
    private ?string $contractualObligations = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['isms_context:read', 'isms_context:write'])]
    private ?string $ismsPolicy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['isms_context:read', 'isms_context:write'])]
    private ?string $rolesAndResponsibilities = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['isms_context:read', 'isms_context:write'])]
    private ?\DateTimeInterface $lastReviewDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['isms_context:read', 'isms_context:write'])]
    private ?\DateTimeInterface $nextReviewDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['isms_context:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['isms_context:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

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

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }
}
