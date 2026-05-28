<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TisaxLicenseConfirmationRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Records a user's acceptance of the ENX licence obligation before a
 * VDA-ISA workbook upload (TISAX BYO import wizard Step 0).
 *
 * A confirmation is valid for 24 hours per upload session.
 * `sessionToken` stores the SHA-256 of the Symfony session ID so we
 * can correlate the disclaimer step with the subsequent upload step
 * without storing the raw session credential.
 */
#[ORM\Entity(repositoryClass: TisaxLicenseConfirmationRepository::class)]
#[ORM\Table(name: 'tisax_license_confirmation')]
#[ORM\Index(name: 'idx_tlc_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_tlc_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_tlc_confirmed_at', columns: ['confirmed_at'])]
class TisaxLicenseConfirmation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $confirmedAt = null;

    #[ORM\Column(length: 255)]
    private string $workbookFilename = '';

    #[ORM\Column(length: 45)]
    private string $ipAddress = '';

    /**
     * SHA-256 of the Symfony session ID at confirmation time.
     * Prevents storing raw credentials; used for step-to-step correlation.
     */
    #[ORM\Column(length: 64)]
    private string $sessionToken = '';

    public function __construct()
    {
        $this->confirmedAt = new DateTimeImmutable();
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getConfirmedAt(): ?DateTimeInterface
    {
        return $this->confirmedAt;
    }

    public function setConfirmedAt(?DateTimeInterface $confirmedAt): static
    {
        $this->confirmedAt = $confirmedAt;
        return $this;
    }

    public function getWorkbookFilename(): string
    {
        return $this->workbookFilename;
    }

    public function setWorkbookFilename(string $workbookFilename): static
    {
        $this->workbookFilename = $workbookFilename;
        return $this;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getSessionToken(): string
    {
        return $this->sessionToken;
    }

    public function setSessionToken(string $sessionToken): static
    {
        $this->sessionToken = $sessionToken;
        return $this;
    }

    /**
     * Whether this confirmation is still valid (within 24 hours).
     */
    public function isValid(): bool
    {
        if ($this->confirmedAt === null) {
            return false;
        }
        $expiresAt = (new DateTimeImmutable())->modify('-24 hours');
        return $this->confirmedAt > $expiresAt;
    }
}
