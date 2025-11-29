<?php

namespace App\Entity;

use DateTimeImmutable;
use Deprecated;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this email')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string|null The hashed password (for local authentication)
     */
    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 100)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100)]
    private ?string $lastName = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isVerified = false;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $authProvider = null; // 'local', 'azure_oauth', 'azure_saml'

    #[ORM\Column(length: 255, unique: true, nullable: true)]
    private ?string $azureObjectId = null; // Azure AD Object ID

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $azureTenantId = null; // Azure AD Tenant ID

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $azureMetadata = null; // Additional Azure metadata

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, Role>
     */
    #[ORM\ManyToMany(targetEntity: Role::class, inversedBy: 'users')]
    #[ORM\JoinTable(name: 'user_roles')]
    private Collection $customRoles;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $department = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $jobTitle = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $phoneNumber = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $profilePicture = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $language = 'de';

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $timezone = 'Europe/Berlin';

    #[ORM\ManyToOne(targetEntity: Tenant::class, inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Tenant $tenant = null;

    /**
     * @var Collection<int, MfaToken>
     */
    #[ORM\OneToMany(targetEntity: MfaToken::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $mfaTokens;

    public function __construct()
    {
        $this->customRoles = new ArrayCollection();
        $this->mfaTokens = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * Alias for getUserIdentifier() for backward compatibility
     */
    #[Deprecated(message: 'Use getUserIdentifier() instead')]
    public function getUsername(): string
    {
        return $this->getUserIdentifier();
    }

    /**
     * @see UserInterface
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;

        // Add roles from custom role entities
        foreach ($this->customRoles as $customRole) {
            $roles[] = $customRole->getName();
        }

        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * Get only the stored system roles (without automatic ROLE_USER and custom roles)
     * This is used for form pre-population
     *
     * @return list<string>
     */
    public function getStoredRoles(): array
    {
        return $this->roles;
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): static
    {
        $this->password = $password;
        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;
        return $this;
    }

    public function getAuthProvider(): ?string
    {
        return $this->authProvider;
    }

    public function setAuthProvider(?string $authProvider): static
    {
        $this->authProvider = $authProvider;
        return $this;
    }

    public function getAzureObjectId(): ?string
    {
        return $this->azureObjectId;
    }

    public function setAzureObjectId(?string $azureObjectId): static
    {
        $this->azureObjectId = $azureObjectId;
        return $this;
    }

    public function getAzureTenantId(): ?string
    {
        return $this->azureTenantId;
    }

    public function setAzureTenantId(?string $azureTenantId): static
    {
        $this->azureTenantId = $azureTenantId;
        return $this;
    }

    public function getAzureMetadata(): ?array
    {
        return $this->azureMetadata;
    }

    public function setAzureMetadata(?array $azureMetadata): static
    {
        $this->azureMetadata = $azureMetadata;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getLastLoginAt(): ?DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @return Collection<int, Role>
     */
    public function getCustomRoles(): Collection
    {
        return $this->customRoles;
    }

    public function addCustomRole(Role $customRole): static
    {
        if (!$this->customRoles->contains($customRole)) {
            $this->customRoles->add($customRole);
        }

        return $this;
    }

    public function removeCustomRole(Role $customRole): static
    {
        $this->customRoles->removeElement($customRole);
        return $this;
    }

    public function getDepartment(): ?string
    {
        return $this->department;
    }

    public function setDepartment(?string $department): static
    {
        $this->department = $department;
        return $this;
    }

    public function getJobTitle(): ?string
    {
        return $this->jobTitle;
    }

    public function setJobTitle(?string $jobTitle): static
    {
        $this->jobTitle = $jobTitle;
        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): static
    {
        $this->phoneNumber = $phoneNumber;
        return $this;
    }

    public function getProfilePicture(): ?string
    {
        return $this->profilePicture;
    }

    public function setProfilePicture(?string $profilePicture): static
    {
        $this->profilePicture = $profilePicture;
        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): static
    {
        $this->language = $language;
        return $this;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function setTimezone(?string $timezone): static
    {
        $this->timezone = $timezone;
        return $this;
    }

    /**
     * Check if user has a specific permission
     */
    public function hasPermission(string $permission): bool
    {
        foreach ($this->customRoles as $customRole) {
            if ($customRole->hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if user is authenticated via Azure
     */
    public function isAzureUser(): bool
    {
        return in_array($this->authProvider, ['azure_oauth', 'azure_saml']);
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

    /**
     * @return Collection<int, MfaToken>
     */
    public function getMfaTokens(): Collection
    {
        return $this->mfaTokens;
    }

    public function addMfaToken(MfaToken $mfaToken): static
    {
        if (!$this->mfaTokens->contains($mfaToken)) {
            $this->mfaTokens->add($mfaToken);
            $mfaToken->setUser($this);
        }

        return $this;
    }

    public function removeMfaToken(MfaToken $mfaToken): static
    {
        // set the owning side to null (unless already changed)
        if ($this->mfaTokens->removeElement($mfaToken) && $mfaToken->getUser() === $this) {
            $mfaToken->setUser(null);
        }

        return $this;
    }

    /**
     * Check if user has any active MFA tokens
     */
    public function hasMfaEnabled(): bool
    {
        foreach ($this->mfaTokens as $token) {
            if ($token->isActive()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get count of active MFA tokens
     */
    public function getActiveMfaTokenCount(): int
    {
        $count = 0;
        foreach ($this->mfaTokens as $token) {
            if ($token->isActive()) {
                $count++;
            }
        }
        return $count;
    }
}
