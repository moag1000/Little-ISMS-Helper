<?php

declare(strict_types=1);

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
    // -------------------------------------------------------------------------
    // Phase 8M.5 — Holding-Rollen (Konzern-Strukturen)
    // -------------------------------------------------------------------------

    /**
     * Konzern-ISB / Group-CISO. Lese-quer-Kapazität über alle Tochter-Tenants.
     * Inherits ROLE_AUDITOR — kann ISMS-Dokumente und Risk-Register lesen,
     * aber keine Schreib-/Lösch-Operationen ausführen.
     * Einsatz: Board-Dashboards, Group-Reports, Portfolio-Übersicht.
     */
    public const ROLE_GROUP_CISO = 'ROLE_GROUP_CISO';

    /**
     * Read-Only auf alle Tochter-Tenants. Für externe oder interne Konzern-Auditoren,
     * die alle Subsidiaries prüfen müssen, ohne Schreibrechte zu haben.
     * Inherits ROLE_AUDITOR.
     * Einsatz: Konzerninterne Audits, Regulierungs-Reviews, Portfolio-Berichte.
     */
    public const ROLE_KONZERN_AUDITOR = 'ROLE_KONZERN_AUDITOR';

    // -------------------------------------------------------------------------

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

    /** Generic SSO: external subject claim (sub) — unique per provider, not globally. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ssoExternalId = null;

    #[ORM\ManyToOne(targetEntity: IdentityProvider::class)]
    #[ORM\JoinColumn(name: 'sso_provider_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?IdentityProvider $ssoProvider = null;

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

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $skipWelcomePage = false;

    /**
     * Sprint 13 — Guided Tour. Liste der Tour-IDs, die der User
     * durchlaufen hat (z. B. ['junior', 'cm']). Per ID, damit
     * Role-Umstieg eines Users alte Completions nicht aufräumt.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $completedTours = [];

    /**
     * Audit-S5 P-12 — previous QM-System background.
     *
     * Controls visibility of "Norm-Bridge" hints (ISO 27001 ↔ ISO 9001 etc.)
     * underneath form-labels. Set to 'iso_9001' for the ~80 % of customers
     * that join from an existing ISO-9001 QM-System; defaults to NULL =
     * never show bridges. Allowed values: 'iso_9001' | 'iso_14001' |
     * 'other' | 'none' | NULL.
     */
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $previousQmsBackground = null;

    // -------------------------------------------------------------------------
    // FairyAurora v4.0 — Alva Companion user preferences
    // -------------------------------------------------------------------------

    /**
     * Whether the Alva companion dock is shown to this user.
     * Default: true (visible by default to showcase v4.0 delight).
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $alvaCompanionEnabled = true;

    /**
     * Size of the Alva dock widget.
     * Allowed values: 'sm' | 'md' | 'lg'
     */
    #[ORM\Column(length: 8, options: ['default' => 'md'])]
    private string $alvaCompanionSize = 'md';

    /**
     * Corner position of the Alva dock on screen.
     * Allowed values: 'bottom-right' | 'bottom-left' | 'top-right' | 'top-left'
     */
    #[ORM\Column(length: 20, options: ['default' => 'bottom-right'])]
    private string $alvaCompanionPosition = 'bottom-right';

    /**
     * ISO 27001 §7.2 Competence — structured per-user competency tracking.
     * JSON array: [{name, category: security|compliance|technical|leadership,
     *   level: 1-5, certifiedBy: string|null, certifiedAt: ISO-date|null, expiresAt: ISO-date|null}]
     *
     * @var array<int, array<string, mixed>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $competencies = null;

    // -------------------------------------------------------------------------
    // Sprint 6a — F3 Notification preferences
    // -------------------------------------------------------------------------

    #[ORM\Column(name: 'in_app_notifications_enabled')]
    private bool $inAppNotificationsEnabled = true;

    #[ORM\Column(name: 'last_seen_notifications', nullable: true)]
    private ?DateTimeImmutable $lastSeenNotifications = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class, inversedBy: 'users')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Tenant $tenant = null;

    /**
     * @var Collection<int, MfaToken>
     */
    #[ORM\OneToMany(targetEntity: MfaToken::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $mfaTokens;

    /**
     * Inverse side of {@see Person::$linkedUser}. Read-only convenience
     * accessor for the Person-Rollout (governance roles point to
     * Person, not User; we still want to surface "is this User backed
     * by a Person record?" cheaply). Lazy-loaded — Doctrine will only
     * issue a SELECT when {@see self::getLinkedPerson()} is called.
     *
     * Person.linkedUser is `ManyToOne` (no unique constraint) so we
     * model the inverse as a collection and treat the first row as the
     * canonical link. Business-rule = at most one Person per User; if
     * multiple rows show up, the first by id wins.
     *
     * @var Collection<int, Person>
     */
    #[ORM\OneToMany(targetEntity: Person::class, mappedBy: 'linkedUser')]
    private Collection $linkedPersons;

    public function __construct()
    {
        $this->customRoles = new ArrayCollection();
        $this->mfaTokens = new ArrayCollection();
        $this->linkedPersons = new ArrayCollection();
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

    /**
     * Read-only accessor for the canonical {@see Person} record linked
     * to this User. Returns null when no Person profile exists yet.
     *
     * Used by Person-Rollout consumers (Asset/Risk/Control/Document
     * owner-pickers, Policy-Wizard role validators) to decide whether
     * a legacy User-id can be resolved to a Person without an
     * additional repository call.
     */
    public function getLinkedPerson(): ?Person
    {
        if ($this->linkedPersons->isEmpty()) {
            return null;
        }

        return $this->linkedPersons->first() ?: null;
    }

    /**
     * @return Collection<int, Person>
     */
    public function getLinkedPersons(): Collection
    {
        return $this->linkedPersons;
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

    public function getSsoExternalId(): ?string
    {
        return $this->ssoExternalId;
    }

    public function setSsoExternalId(?string $id): static
    {
        $this->ssoExternalId = $id;
        return $this;
    }

    public function getSsoProvider(): ?IdentityProvider
    {
        return $this->ssoProvider;
    }

    public function setSsoProvider(?IdentityProvider $provider): static
    {
        $this->ssoProvider = $provider;
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

    public function isSkipWelcomePage(): bool
    {
        return $this->skipWelcomePage;
    }

    public function setSkipWelcomePage(bool $skipWelcomePage): static
    {
        $this->skipWelcomePage = $skipWelcomePage;
        return $this;
    }

    /** @return list<string> */
    public function getCompletedTours(): array
    {
        return $this->completedTours;
    }

    public function hasCompletedTour(string $tourId): bool
    {
        return in_array($tourId, $this->completedTours, true);
    }

    public function markTourCompleted(string $tourId): static
    {
        if (!$this->hasCompletedTour($tourId)) {
            $this->completedTours[] = $tourId;
        }
        return $this;
    }

    public function resetTour(string $tourId): static
    {
        $this->completedTours = array_values(array_filter(
            $this->completedTours,
            static fn(string $t): bool => $t !== $tourId,
        ));
        return $this;
    }

    public function resetAllTours(): static
    {
        $this->completedTours = [];
        return $this;
    }

    /**
     * Audit-S5 P-12 — read previous QM-System background.
     *
     * @return string|null One of 'iso_9001' | 'iso_14001' | 'other' | 'none' | NULL.
     */
    public function getPreviousQmsBackground(): ?string
    {
        return $this->previousQmsBackground;
    }

    public function setPreviousQmsBackground(?string $previousQmsBackground): static
    {
        $allowed = ['iso_9001', 'iso_14001', 'other', 'none', null];
        if (!in_array($previousQmsBackground, $allowed, true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Invalid previousQmsBackground "%s". Allowed: iso_9001, iso_14001, other, none, null.',
                $previousQmsBackground,
            ));
        }
        $this->previousQmsBackground = $previousQmsBackground;
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

    // -------------------------------------------------------------------------
    // FairyAurora v4.0 — Alva Companion getters / setters
    // -------------------------------------------------------------------------

    public function isAlvaCompanionEnabled(): bool
    {
        return $this->alvaCompanionEnabled;
    }

    /** Alias for Twig (app.user.alvaCompanionEnabled) */
    public function getAlvaCompanionEnabled(): bool
    {
        return $this->alvaCompanionEnabled;
    }

    public function setAlvaCompanionEnabled(bool $alvaCompanionEnabled): static
    {
        $this->alvaCompanionEnabled = $alvaCompanionEnabled;
        return $this;
    }

    public function getAlvaCompanionSize(): string
    {
        return $this->alvaCompanionSize;
    }

    public function setAlvaCompanionSize(string $alvaCompanionSize): static
    {
        $this->alvaCompanionSize = $alvaCompanionSize;
        return $this;
    }

    public function getAlvaCompanionPosition(): string
    {
        return $this->alvaCompanionPosition;
    }

    public function setAlvaCompanionPosition(string $alvaCompanionPosition): static
    {
        $this->alvaCompanionPosition = $alvaCompanionPosition;
        return $this;
    }

    /** @return array<int, array<string, mixed>>|null */
    public function getCompetencies(): ?array
    {
        return $this->competencies;
    }

    /** @param array<int, array<string, mixed>>|null $competencies */
    public function setCompetencies(?array $competencies): static
    {
        $this->competencies = $competencies;
        return $this;
    }

    // -------------------------------------------------------------------------
    // Sprint 6a — F3 Notification preferences
    // -------------------------------------------------------------------------

    public function isInAppNotificationsEnabled(): bool
    {
        return $this->inAppNotificationsEnabled;
    }

    public function setInAppNotificationsEnabled(bool $inAppNotificationsEnabled): static
    {
        $this->inAppNotificationsEnabled = $inAppNotificationsEnabled;
        return $this;
    }

    public function getLastSeenNotifications(): ?DateTimeImmutable
    {
        return $this->lastSeenNotifications;
    }

    public function setLastSeenNotifications(?DateTimeImmutable $lastSeenNotifications): static
    {
        $this->lastSeenNotifications = $lastSeenNotifications;
        return $this;
    }
}
