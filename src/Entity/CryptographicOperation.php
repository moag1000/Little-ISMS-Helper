<?php

namespace App\Entity;

use DateTimeInterface;
use DateTimeImmutable;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Repository\CryptographicOperationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CryptographicOperationRepository::class)]
#[ORM\Index(columns: ['operation_type'], name: 'idx_crypto_operation_type')]
#[ORM\Index(columns: ['timestamp'], name: 'idx_crypto_timestamp')]
#[ORM\Index(columns: ['user_id'], name: 'idx_crypto_user')]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['crypto:read']],
    denormalizationContext: ['groups' => ['crypto:write']]
)]
class CryptographicOperation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['crypto:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Groups(['crypto:read', 'crypto:write'])]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['encrypt', 'decrypt', 'sign', 'verify', 'hash', 'key_generation', 'key_rotation', 'key_deletion'])]
    private ?string $operationType = null;

    #[ORM\Column(length: 100)]
    #[Groups(['crypto:read', 'crypto:write'])]
    #[Assert\NotBlank]
    private ?string $algorithm = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['crypto:read', 'crypto:write'])]
    private ?int $keyLength = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['crypto:read', 'crypto:write'])]
    private ?string $keyIdentifier = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['crypto:read', 'crypto:write'])]
    private ?string $purpose = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['crypto:read', 'crypto:write'])]
    private ?string $dataClassification = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['crypto:read'])]
    private ?User $user = null;

    // New relationship for data reuse
    #[ORM\ManyToOne(targetEntity: Asset::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['crypto:read', 'crypto:write'])]
    private ?Asset $relatedAsset = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['crypto:read', 'crypto:write'])]
    private ?string $applicationComponent = null;

    #[ORM\Column(length: 50)]
    #[Groups(['crypto:read', 'crypto:write'])]
    #[Assert\Choice(choices: ['success', 'failure', 'pending'])]
    private ?string $status = 'success';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['crypto:read', 'crypto:write'])]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['crypto:read'])]
    private ?DateTimeInterface $timestamp = null;

    #[ORM\Column(length: 45, nullable: true)]
    #[Groups(['crypto:read', 'crypto:write'])]
    private ?string $ipAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['crypto:read', 'crypto:write'])]
    private ?string $metadata = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['crypto:read', 'crypto:write'])]
    private ?bool $complianceRelevant = true;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

    public function __construct()
    {
        $this->timestamp = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOperationType(): ?string
    {
        return $this->operationType;
    }

    public function setOperationType(string $operationType): static
    {
        $this->operationType = $operationType;
        return $this;
    }

    public function getAlgorithm(): ?string
    {
        return $this->algorithm;
    }

    public function setAlgorithm(string $algorithm): static
    {
        $this->algorithm = $algorithm;
        return $this;
    }

    public function getKeyLength(): ?int
    {
        return $this->keyLength;
    }

    public function setKeyLength(?int $keyLength): static
    {
        $this->keyLength = $keyLength;
        return $this;
    }

    public function getKeyIdentifier(): ?string
    {
        return $this->keyIdentifier;
    }

    public function setKeyIdentifier(?string $keyIdentifier): static
    {
        $this->keyIdentifier = $keyIdentifier;
        return $this;
    }

    public function getPurpose(): ?string
    {
        return $this->purpose;
    }

    public function setPurpose(?string $purpose): static
    {
        $this->purpose = $purpose;
        return $this;
    }

    public function getDataClassification(): ?string
    {
        return $this->dataClassification;
    }

    public function setDataClassification(?string $dataClassification): static
    {
        $this->dataClassification = $dataClassification;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getApplicationComponent(): ?string
    {
        return $this->applicationComponent;
    }

    public function setApplicationComponent(?string $applicationComponent): static
    {
        $this->applicationComponent = $applicationComponent;
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

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getTimestamp(): ?DateTimeInterface
    {
        return $this->timestamp;
    }

    public function setTimestamp(DateTimeInterface $timestamp): static
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getMetadata(): ?string
    {
        return $this->metadata;
    }

    public function setMetadata(?string $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function isComplianceRelevant(): ?bool
    {
        return $this->complianceRelevant;
    }

    public function setComplianceRelevant(bool $complianceRelevant): static
    {
        $this->complianceRelevant = $complianceRelevant;
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

    public function getRelatedAsset(): ?Asset
    {
        return $this->relatedAsset;
    }

    public function setRelatedAsset(?Asset $relatedAsset): static
    {
        $this->relatedAsset = $relatedAsset;
        return $this;
    }
}
