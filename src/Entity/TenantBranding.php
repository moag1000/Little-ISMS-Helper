<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TenantBrandingRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-tenant branding for PDF export of generated policy documents
 * (Sprint W7). Carries letterhead HTML, logo path, primary/secondary
 * colors, and font family. 1:1 with Tenant.
 */
#[ORM\Entity(repositoryClass: TenantBrandingRepository::class)]
#[ORM\Table(name: 'tenant_branding')]
#[ORM\UniqueConstraint(name: 'uq_tenant_branding_tenant', columns: ['tenant_id'])]
class TenantBranding
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoPath = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $headerHtml = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $footerHtml = null;

    #[ORM\Column(length: 16, options: ['default' => '#0d6efd'])]
    private string $primaryColor = '#0d6efd';

    #[ORM\Column(length: 16, options: ['default' => '#6c757d'])]
    private string $secondaryColor = '#6c757d';

    #[ORM\Column(length: 64, options: ['default' => 'Inter'])]
    private string $fontFamily = 'Inter';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?User $updatedByUser = null;

    public function __construct()
    {
        $this->updatedAt = new DateTimeImmutable();
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

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): static
    {
        $this->logoPath = $logoPath;
        return $this;
    }

    public function getHeaderHtml(): ?string
    {
        return $this->headerHtml;
    }

    public function setHeaderHtml(?string $headerHtml): static
    {
        $this->headerHtml = $headerHtml;
        return $this;
    }

    public function getFooterHtml(): ?string
    {
        return $this->footerHtml;
    }

    public function setFooterHtml(?string $footerHtml): static
    {
        $this->footerHtml = $footerHtml;
        return $this;
    }

    public function getPrimaryColor(): string
    {
        return $this->primaryColor;
    }

    public function setPrimaryColor(string $primaryColor): static
    {
        $this->primaryColor = $primaryColor;
        return $this;
    }

    public function getSecondaryColor(): string
    {
        return $this->secondaryColor;
    }

    public function setSecondaryColor(string $secondaryColor): static
    {
        $this->secondaryColor = $secondaryColor;
        return $this;
    }

    public function getFontFamily(): string
    {
        return $this->fontFamily;
    }

    public function setFontFamily(string $fontFamily): static
    {
        $this->fontFamily = $fontFamily;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getUpdatedByUser(): ?User
    {
        return $this->updatedByUser;
    }

    public function setUpdatedByUser(?User $updatedByUser): static
    {
        $this->updatedByUser = $updatedByUser;
        return $this;
    }
}
