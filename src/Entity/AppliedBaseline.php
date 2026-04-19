<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AppliedBaselineRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audit-trail of which industry baseline was applied to which tenant,
 * when, by whom, and what was actually created as a result. Lets a
 * future auditor answer "why does this tenant have these 12 preset
 * risks — where did they come from?".
 */
#[ORM\Entity(repositoryClass: AppliedBaselineRepository::class)]
#[ORM\Table(name: 'applied_baseline')]
#[ORM\UniqueConstraint(
    name: 'uniq_applied_baseline_tenant_code',
    columns: ['tenant_id', 'baseline_code']
)]
#[ORM\Index(name: 'idx_applied_baseline_tenant', columns: ['tenant_id'])]
class AppliedBaseline
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 50)]
    private string $baselineCode;

    #[ORM\Column(length: 20)]
    private string $baselineVersion;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $appliedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $appliedAt;

    /**
     * What was actually created (for audit transparency).
     * @var array<string,int>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $createdSummary = [];

    public function __construct()
    {
        $this->appliedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getBaselineCode(): string
    {
        return $this->baselineCode;
    }

    public function setBaselineCode(string $code): static
    {
        $this->baselineCode = $code;
        return $this;
    }

    public function getBaselineVersion(): string
    {
        return $this->baselineVersion;
    }

    public function setBaselineVersion(string $version): static
    {
        $this->baselineVersion = $version;
        return $this;
    }

    public function getAppliedBy(): ?User
    {
        return $this->appliedBy;
    }

    public function setAppliedBy(?User $user): static
    {
        $this->appliedBy = $user;
        return $this;
    }

    public function getAppliedAt(): DateTimeImmutable
    {
        return $this->appliedAt;
    }

    /** @return array<string,int> */
    public function getCreatedSummary(): array
    {
        return $this->createdSummary;
    }

    /** @param array<string,int> $summary */
    public function setCreatedSummary(array $summary): static
    {
        $this->createdSummary = $summary;
        return $this;
    }
}
