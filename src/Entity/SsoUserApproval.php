<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SsoUserApprovalRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SsoUserApprovalRepository::class)]
#[ORM\Table(name: 'sso_user_approval')]
#[ORM\Index(name: 'idx_ssoa_status', columns: ['status'])]
#[ORM\Index(name: 'idx_ssoa_provider_email', columns: ['provider_id', 'email'])]
#[ORM\HasLifecycleCallbacks]
class SsoUserApproval
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: IdentityProvider::class)]
    #[ORM\JoinColumn(name: 'provider_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?IdentityProvider $provider = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private ?string $externalId = null;

    /** Full claim payload (ID-Token + UserInfo) */
    #[ORM\Column(type: Types::JSON)]
    private array $claims = [];

    #[ORM\Column(length: 16, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $requestedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reviewed_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $reviewedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rejectReason = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->requestedAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $t): self { $this->tenant = $t; return $this; }
    public function getProvider(): ?IdentityProvider { return $this->provider; }
    public function setProvider(IdentityProvider $p): self { $this->provider = $p; return $this; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(string $e): self { $this->email = $e; return $this; }
    public function getExternalId(): ?string { return $this->externalId; }
    public function setExternalId(string $id): self { $this->externalId = $id; return $this; }
    public function getClaims(): array { return $this->claims; }
    public function setClaims(array $c): self { $this->claims = $c; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): self { $this->status = $s; return $this; }
    public function getRequestedAt(): ?DateTimeImmutable { return $this->requestedAt; }
    public function getReviewedBy(): ?User { return $this->reviewedBy; }
    public function setReviewedBy(?User $u): self { $this->reviewedBy = $u; return $this; }
    public function getReviewedAt(): ?DateTimeImmutable { return $this->reviewedAt; }
    public function setReviewedAt(?DateTimeImmutable $d): self { $this->reviewedAt = $d; return $this; }
    public function getRejectReason(): ?string { return $this->rejectReason; }
    public function setRejectReason(?string $r): self { $this->rejectReason = $r; return $this; }
    public function isPending(): bool { return $this->status === self::STATUS_PENDING; }
}
