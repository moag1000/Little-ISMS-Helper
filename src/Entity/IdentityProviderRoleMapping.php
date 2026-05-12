<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IdentityProviderRoleMappingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Maps an IdP claim value expression to a Symfony role (and optional permissions).
 *
 * Priority ordering: lower int = higher priority.
 * ClaimToRoleResolver iterates active mappings ordered by priority ASC,
 * returns on first match.
 */
#[ORM\Entity(repositoryClass: IdentityProviderRoleMappingRepository::class)]
#[ORM\Table(name: 'identity_provider_role_mapping')]
#[ORM\Index(columns: ['identity_provider_id', 'priority'], name: 'idx_iprm_idp_priority')]
class IdentityProviderRoleMapping
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Null = global provider mapping; non-null = tenant-scoped. Mirrors the IdP's own tenant. */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: IdentityProvider::class, inversedBy: 'roleMappings')]
    #[ORM\JoinColumn(name: 'identity_provider_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?IdentityProvider $identityProvider = null;

    /** IdP claim key to inspect, e.g. "groups", "roles", "department". */
    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    private string $claimKey = '';

    /**
     * Expression to match against the claim value.
     * Simple string match: exact equality or fnmatch glob.
     * Example: "isms-admin", "staff-*", "ou=security,dc=acme,dc=com".
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $claimValueExpression = '';

    /** Symfony role assigned when this mapping matches. */
    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^ROLE_[A-Z_]+$/', message: 'Must be a valid ROLE_ constant.')]
    private string $assignedRole = 'ROLE_USER';

    /** @var list<string> Additional permission strings granted alongside the role. */
    #[ORM\Column(type: Types::JSON)]
    private array $assignedPermissions = [];

    /** Lower = checked first. */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $priority = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $auditDescription = null;

    public function getId(): ?int { return $this->id; }

    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): self { $this->tenant = $tenant; return $this; }

    public function getIdentityProvider(): ?IdentityProvider { return $this->identityProvider; }
    public function setIdentityProvider(?IdentityProvider $idp): self { $this->identityProvider = $idp; return $this; }

    public function getClaimKey(): string { return $this->claimKey; }
    public function setClaimKey(string $v): self { $this->claimKey = $v; return $this; }

    public function getClaimValueExpression(): string { return $this->claimValueExpression; }
    public function setClaimValueExpression(string $v): self { $this->claimValueExpression = $v; return $this; }

    public function getAssignedRole(): string { return $this->assignedRole; }
    public function setAssignedRole(string $v): self { $this->assignedRole = $v; return $this; }

    /** @return list<string> */
    public function getAssignedPermissions(): array { return $this->assignedPermissions; }
    /** @param list<string> $perms */
    public function setAssignedPermissions(array $perms): self { $this->assignedPermissions = array_values($perms); return $this; }

    public function getPriority(): int { return $this->priority; }
    public function setPriority(int $v): self { $this->priority = $v; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $v): self { $this->isActive = $v; return $this; }

    public function getAuditDescription(): ?string { return $this->auditDescription; }
    public function setAuditDescription(?string $v): self { $this->auditDescription = $v; return $this; }

    /**
     * Evaluate whether $claimValue matches this mapping's expression.
     * Supports exact string match and fnmatch glob patterns.
     */
    public function matches(mixed $claimValue): bool
    {
        if (!$this->isActive) {
            return false;
        }
        $expr = $this->claimValueExpression;
        $vals = is_array($claimValue) ? $claimValue : [(string) $claimValue];
        foreach ($vals as $v) {
            $v = (string) $v;
            if ($v === $expr || fnmatch($expr, $v)) {
                return true;
            }
        }
        return false;
    }
}
