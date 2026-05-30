<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizationSecurityProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-tenant single source of truth for policy parameter values + org-context
 * flags. See design spec 2026-05-30. Values resolved via PolicyParameterResolver.
 */
#[ORM\Entity(repositoryClass: OrganizationSecurityProfileRepository::class)]
#[ORM\Table(name: 'organization_security_profile')]
#[ORM\UniqueConstraint(name: 'uniq_osp_tenant', columns: ['tenant_id'])]
class OrganizationSecurityProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?int $tenantId = null;

    /** @var array<string, mixed> */
    // Column named parameter_values (not `values`) to avoid the MariaDB/MySQL
    // reserved word, which Doctrine does not reliably quote in hydration SQL.
    #[ORM\Column(name: 'parameter_values', type: Types::JSON)]
    private array $values = [];

    /** @var array<string, bool> */
    #[ORM\Column(type: Types::JSON)]
    private array $flags = [];

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sectorKey = null;

    #[ORM\Version]
    #[ORM\Column(name: 'lock_version', type: 'integer', options: ['default' => 1])]
    private int $lockVersion = 1;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function setTenantId(?int $tenantId): static
    {
        $this->tenantId = $tenantId;

        return $this;
    }

    public function getValue(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function setValue(string $key, mixed $value): static
    {
        $this->values[$key] = $value;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getValues(): array
    {
        return $this->values;
    }

    public function getFlag(string $key): bool
    {
        return (bool) ($this->flags[$key] ?? false);
    }

    public function setFlag(string $key, bool $value): static
    {
        $this->flags[$key] = $value;

        return $this;
    }

    public function getSectorKey(): ?string
    {
        return $this->sectorKey;
    }

    public function setSectorKey(?string $sectorKey): static
    {
        $this->sectorKey = $sectorKey;

        return $this;
    }
}
