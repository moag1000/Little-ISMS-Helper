<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IdentityProviderRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: IdentityProviderRepository::class)]
#[ORM\Table(name: 'identity_provider')]
#[ORM\UniqueConstraint(name: 'uniq_idp_slug_tenant', columns: ['slug', 'tenant_id'])]
#[ORM\HasLifecycleCallbacks]
class IdentityProvider
{
    public const TYPE_OIDC = 'oidc';
    public const TYPE_OAUTH2 = 'oauth2_generic';

    public const DOMAIN_MODE_DISABLED = 'disabled';
    public const DOMAIN_MODE_OPTIONAL = 'optional';
    public const DOMAIN_MODE_ENFORCE = 'enforce';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Null = global provider (system-wide); non-null = tenant-scoped */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Regex('/^[a-z0-9][a-z0-9_-]{1,62}[a-z0-9]$/', message: 'Slug must be lowercase alphanumeric (with dashes/underscores).')]
    private ?string $slug = null;

    #[ORM\Column(length: 128)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(length: 32)]
    #[Assert\Choice(choices: [self::TYPE_OIDC, self::TYPE_OAUTH2])]
    private string $type = self::TYPE_OIDC;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $clientId = null;

    /** AEAD-encrypted at rest */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $clientSecretEncrypted = null;

    #[ORM\Column(length: 512, nullable: true)]
    #[Assert\Url(requireTld: false)]
    private ?string $discoveryUrl = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $issuer = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $authorizationEndpoint = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $tokenEndpoint = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $userinfoEndpoint = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $jwksUri = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $scopes = ['openid', 'profile', 'email'];

    /** Claim → User-field mapping. e.g. {"email":"email","given_name":"firstName","family_name":"lastName"} */
    #[ORM\Column(type: Types::JSON)]
    private array $attributeMap = [
        'email' => 'email',
        'given_name' => 'firstName',
        'family_name' => 'lastName',
    ];

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $buttonLabel = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $buttonIcon = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $buttonColor = null;

    /** @var list<string> Email-domain allow-list, e.g. ["@acme.com","@acme.de"] */
    #[ORM\Column(type: Types::JSON)]
    private array $domainBindings = [];

    #[ORM\Column(length: 16, options: ['default' => self::DOMAIN_MODE_OPTIONAL])]
    #[Assert\Choice(choices: [self::DOMAIN_MODE_DISABLED, self::DOMAIN_MODE_OPTIONAL, self::DOMAIN_MODE_ENFORCE])]
    private string $domainBindingMode = self::DOMAIN_MODE_OPTIONAL;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $jitProvisioning = true;

    /** When false: new users land in approval queue. */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $autoApprove = false;

    #[ORM\Column(length: 32, options: ['default' => 'ROLE_USER'])]
    private string $defaultRole = 'ROLE_USER';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): self { $this->tenant = $tenant; return $this; }
    public function isGlobal(): bool { return $this->tenant === null; }

    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(string $slug): self { $this->slug = $slug; return $this; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): self { $this->type = $type; return $this; }

    public function isEnabled(): bool { return $this->enabled; }
    public function setEnabled(bool $enabled): self { $this->enabled = $enabled; return $this; }

    public function getClientId(): ?string { return $this->clientId; }
    public function setClientId(string $clientId): self { $this->clientId = $clientId; return $this; }

    public function getClientSecretEncrypted(): ?string { return $this->clientSecretEncrypted; }
    public function setClientSecretEncrypted(?string $value): self { $this->clientSecretEncrypted = $value; return $this; }

    public function getDiscoveryUrl(): ?string { return $this->discoveryUrl; }
    public function setDiscoveryUrl(?string $url): self { $this->discoveryUrl = $url; return $this; }

    public function getIssuer(): ?string { return $this->issuer; }
    public function setIssuer(?string $v): self { $this->issuer = $v; return $this; }

    public function getAuthorizationEndpoint(): ?string { return $this->authorizationEndpoint; }
    public function setAuthorizationEndpoint(?string $v): self { $this->authorizationEndpoint = $v; return $this; }

    public function getTokenEndpoint(): ?string { return $this->tokenEndpoint; }
    public function setTokenEndpoint(?string $v): self { $this->tokenEndpoint = $v; return $this; }

    public function getUserinfoEndpoint(): ?string { return $this->userinfoEndpoint; }
    public function setUserinfoEndpoint(?string $v): self { $this->userinfoEndpoint = $v; return $this; }

    public function getJwksUri(): ?string { return $this->jwksUri; }
    public function setJwksUri(?string $v): self { $this->jwksUri = $v; return $this; }

    /** @return list<string> */
    public function getScopes(): array { return $this->scopes; }
    /** @param list<string> $scopes */
    public function setScopes(array $scopes): self { $this->scopes = array_values($scopes); return $this; }

    /** @return array<string,string> */
    public function getAttributeMap(): array { return $this->attributeMap; }
    /** @param array<string,string> $map */
    public function setAttributeMap(array $map): self { $this->attributeMap = $map; return $this; }

    public function getButtonLabel(): ?string { return $this->buttonLabel; }
    public function setButtonLabel(?string $v): self { $this->buttonLabel = $v; return $this; }

    public function getButtonIcon(): ?string { return $this->buttonIcon; }
    public function setButtonIcon(?string $v): self { $this->buttonIcon = $v; return $this; }

    public function getButtonColor(): ?string { return $this->buttonColor; }
    public function setButtonColor(?string $v): self { $this->buttonColor = $v; return $this; }

    /** @return list<string> */
    public function getDomainBindings(): array { return $this->domainBindings; }
    /** @param list<string> $list */
    public function setDomainBindings(array $list): self { $this->domainBindings = array_values($list); return $this; }

    public function getDomainBindingMode(): string { return $this->domainBindingMode; }
    public function setDomainBindingMode(string $mode): self { $this->domainBindingMode = $mode; return $this; }

    public function isJitProvisioning(): bool { return $this->jitProvisioning; }
    public function setJitProvisioning(bool $v): self { $this->jitProvisioning = $v; return $this; }

    public function isAutoApprove(): bool { return $this->autoApprove; }
    public function setAutoApprove(bool $v): self { $this->autoApprove = $v; return $this; }

    public function getDefaultRole(): string { return $this->defaultRole; }
    public function setDefaultRole(string $v): self { $this->defaultRole = $v; return $this; }

    public function getCreatedAt(): ?DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?DateTimeImmutable { return $this->updatedAt; }

    /** Check whether $email matches one of the configured domain bindings. */
    public function matchesEmailDomain(?string $email): bool
    {
        if ($this->domainBindings === []) {
            return $this->domainBindingMode !== self::DOMAIN_MODE_ENFORCE;
        }
        if ($email === null) {
            return false;
        }
        $email = strtolower($email);
        foreach ($this->domainBindings as $binding) {
            $b = strtolower($binding);
            $b = str_starts_with($b, '@') ? $b : '@' . $b;
            if (str_ends_with($email, $b)) {
                return true;
            }
        }
        return false;
    }
}
