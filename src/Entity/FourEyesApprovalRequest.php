<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FourEyesApprovalRequestRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FourEyesApprovalRequestRepository::class)]
#[ORM\Table(name: 'four_eyes_approval_request')]
#[ORM\Index(name: 'idx_feyes_status', columns: ['status'])]
#[ORM\Index(name: 'idx_feyes_tenant_status', columns: ['tenant_id', 'status'])]
#[ORM\Index(name: 'idx_feyes_approver', columns: ['requested_approver_id'])]
class FourEyesApprovalRequest
{
    public const ACTION_INHERITANCE_IMPLEMENT = 'inheritance_implement';
    public const ACTION_MAPPING_OVERRIDE = 'mapping_override';
    public const ACTION_BULK_TAG_REMOVAL = 'bulk_tag_removal';
    public const ACTION_IMPORT_LARGE_COMMIT = 'import_large_commit';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 50)]
    private string $actionType;

    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $requestedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $requestedApprover = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $approvedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $approvedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeInterface $expiresAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->expiresAt = (new DateTimeImmutable())->modify('+7 days');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getActionType(): string
    {
        return $this->actionType;
    }

    public function setActionType(string $type): static
    {
        $this->actionType = $type;
        return $this;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function setPayload(array $payload): static
    {
        $this->payload = $payload;
        return $this;
    }

    public function getRequestedBy(): ?User
    {
        return $this->requestedBy;
    }

    public function setRequestedBy(User $user): static
    {
        $this->requestedBy = $user;
        return $this;
    }

    public function getRequestedApprover(): ?User
    {
        return $this->requestedApprover;
    }

    public function setRequestedApprover(?User $user): static
    {
        $this->requestedApprover = $user;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function setApprovedBy(?User $user): static
    {
        $this->approvedBy = $user;
        return $this;
    }

    public function getApprovedAt(): ?DateTimeInterface
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?DateTimeInterface $at): static
    {
        $this->approvedAt = $at;
        return $this;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $reason): static
    {
        $this->rejectionReason = $reason;
        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(DateTimeInterface $at): static
    {
        $this->expiresAt = $at;
        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new DateTimeImmutable();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING && !$this->isExpired();
    }
}
