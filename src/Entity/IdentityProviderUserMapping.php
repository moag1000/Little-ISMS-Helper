<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IdentityProviderUserMappingRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Links an IdP user identity (sub / external ID) to a local User.
 *
 * The idpClaimsSnapshot is stored as JSON. Production deployments should
 * encrypt the column via a doctrine-encryption-bundle or DB-level key.
 * The snapshot aids JIT re-provisioning and role-mapping diagnostics only.
 *
 * successfulLoginCount is incremented by SsoUserProvisioningService on
 * each successful login and used by RoleMappingWithoutClaimRule.
 */
#[ORM\Entity(repositoryClass: IdentityProviderUserMappingRepository::class)]
#[ORM\Table(name: 'identity_provider_user_mapping')]
#[ORM\UniqueConstraint(name: 'uniq_ipum_idp_sub', columns: ['identity_provider_id', 'idp_user_id'])]
#[ORM\Index(columns: ['user_id'], name: 'idx_ipum_user')]
class IdentityProviderUserMapping
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: IdentityProvider::class)]
    #[ORM\JoinColumn(name: 'identity_provider_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?IdentityProvider $identityProvider = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?User $user = null;

    /** External subject identifier from the IdP (JWT sub claim). */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $idpUserId = '';

    /**
     * Snapshot of the last known IdP claims (JSON).
     *
     * @var array<string,mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $idpClaimsSnapshot = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $firstLoggedInAt = null;

    /** Running count of successful SSO logins — used by AlvaHint RoleMappingWithoutClaimRule. */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $successfulLoginCount = 0;

    public function getId(): ?int { return $this->id; }

    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $t): self { $this->tenant = $t; return $this; }

    public function getIdentityProvider(): ?IdentityProvider { return $this->identityProvider; }
    public function setIdentityProvider(?IdentityProvider $idp): self { $this->identityProvider = $idp; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $u): self { $this->user = $u; return $this; }

    public function getIdpUserId(): string { return $this->idpUserId; }
    public function setIdpUserId(string $v): self { $this->idpUserId = $v; return $this; }

    /** @return array<string,mixed>|null */
    public function getIdpClaimsSnapshot(): ?array { return $this->idpClaimsSnapshot; }
    /** @param array<string,mixed>|null $snapshot */
    public function setIdpClaimsSnapshot(?array $snapshot): self { $this->idpClaimsSnapshot = $snapshot; return $this; }

    public function getLastSyncedAt(): ?DateTimeImmutable { return $this->lastSyncedAt; }
    public function setLastSyncedAt(?DateTimeImmutable $v): self { $this->lastSyncedAt = $v; return $this; }

    public function getFirstLoggedInAt(): ?DateTimeImmutable { return $this->firstLoggedInAt; }
    public function setFirstLoggedInAt(?DateTimeImmutable $v): self { $this->firstLoggedInAt = $v; return $this; }

    public function getSuccessfulLoginCount(): int { return $this->successfulLoginCount; }
    public function incrementSuccessfulLoginCount(): self { ++$this->successfulLoginCount; return $this; }
    public function setSuccessfulLoginCount(int $v): self { $this->successfulLoginCount = $v; return $this; }
}
