<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IdentityProviderRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Identity Provider entity for SSO (OIDC / SAML).
 *
 * Global providers (tenant=null) are visible to all tenants.
 * Tenant-scoped providers are only visible inside their tenant.
 */
#[ORM\Entity(repositoryClass: IdentityProviderRepository::class)]
#[ORM\Table(name: 'identity_provider')]
#[ORM\UniqueConstraint(name: 'uniq_idp_slug', columns: ['slug'])]
class IdentityProvider
{
    public const DOMAIN_MODE_DISABLED = 'disabled';
    public const DOMAIN_MODE_OPTIONAL = 'optional';
    public const DOMAIN_MODE_ENFORCE = 'enforce';

    public const PROTOCOL_OIDC = 'oidc';
    public const PROTOCOL_SAML = 'saml';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 20)]
    private string $protocol = self::PROTOCOL_OIDC;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $clientId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $clientSecret = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $issuerUrl = null;

    #[ORM\Column(length: 20, options: ['default' => self::DOMAIN_MODE_DISABLED])]
    private string $domainBindingMode = self::DOMAIN_MODE_DISABLED;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $domainBindings = [];

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $attributeMap = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $iconUrl = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $buttonLabel = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getProtocol(): string
    {
        return $this->protocol;
    }

    public function setProtocol(string $protocol): static
    {
        $this->protocol = $protocol;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;
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

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function setClientId(?string $clientId): static
    {
        $this->clientId = $clientId;
        return $this;
    }

    public function getClientSecret(): ?string
    {
        return $this->clientSecret;
    }

    public function setClientSecret(?string $clientSecret): static
    {
        $this->clientSecret = $clientSecret;
        return $this;
    }

    public function getIssuerUrl(): ?string
    {
        return $this->issuerUrl;
    }

    public function setIssuerUrl(?string $issuerUrl): static
    {
        $this->issuerUrl = $issuerUrl;
        return $this;
    }

    public function getDomainBindingMode(): string
    {
        return $this->domainBindingMode;
    }

    public function setDomainBindingMode(string $domainBindingMode): static
    {
        $this->domainBindingMode = $domainBindingMode;
        return $this;
    }

    public function getDomainBindings(): array
    {
        return $this->domainBindings ?? [];
    }

    public function setDomainBindings(?array $domainBindings): static
    {
        $this->domainBindings = $domainBindings;
        return $this;
    }

    public function getAttributeMap(): ?array
    {
        return $this->attributeMap;
    }

    public function setAttributeMap(?array $attributeMap): static
    {
        $this->attributeMap = $attributeMap;
        return $this;
    }

    public function getIconUrl(): ?string
    {
        return $this->iconUrl;
    }

    public function setIconUrl(?string $iconUrl): static
    {
        $this->iconUrl = $iconUrl;
        return $this;
    }

    public function getButtonLabel(): ?string
    {
        return $this->buttonLabel;
    }

    public function setButtonLabel(?string $buttonLabel): static
    {
        $this->buttonLabel = $buttonLabel;
        return $this;
    }

    /**
     * Check if an email address matches any of the configured domain bindings.
     */
    public function matchesEmailDomain(string $email): bool
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return false;
        }

        $domain = strtolower($parts[1]);

        foreach ($this->getDomainBindings() as $binding) {
            if (strtolower((string) $binding) === $domain) {
                return true;
            }
        }

        return false;
    }
}
