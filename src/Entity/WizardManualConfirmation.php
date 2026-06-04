<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WizardManualConfirmationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * WizardManualConfirmation
 *
 * Persists a user's "this manual clause is addressed" confirmation for a
 * compliance-wizard check that has NO backing entity to auto-detect from
 * (e.g. ISO 27001 Cl. 5.1 leadership commitment, Cl. 6.3 planning of changes).
 *
 * Data-driven checks (control_coverage, entity_presence, …) never use this —
 * they derive their score from real tenant data. This table only covers the
 * residual genuinely-manual checks so a Junior-ISB can mark them done and the
 * wizard stops reporting them as permanent critical gaps.
 *
 * One row per (tenant, wizard_key, check_key); presence + confirmed=true means
 * "addressed". Removing the row reverts the check to its open state.
 */
#[ORM\Entity(repositoryClass: WizardManualConfirmationRepository::class)]
#[ORM\Table(name: 'wizard_manual_confirmation')]
#[ORM\UniqueConstraint(name: 'uniq_wizard_manual_check', columns: ['tenant_id', 'wizard_key', 'check_key'])]
#[ORM\Index(name: 'idx_wizard_manual_tenant', columns: ['tenant_id'])]
class WizardManualConfirmation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 64)]
    private string $wizardKey;

    #[ORM\Column(length: 128)]
    private string $checkKey;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $confirmed = true;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $confirmedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $confirmedAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    public function __construct()
    {
        $this->confirmedAt = new \DateTimeImmutable();
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

    public function getWizardKey(): string
    {
        return $this->wizardKey;
    }

    public function setWizardKey(string $wizardKey): static
    {
        $this->wizardKey = $wizardKey;
        return $this;
    }

    public function getCheckKey(): string
    {
        return $this->checkKey;
    }

    public function setCheckKey(string $checkKey): static
    {
        $this->checkKey = $checkKey;
        return $this;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed;
    }

    public function setConfirmed(bool $confirmed): static
    {
        $this->confirmed = $confirmed;
        return $this;
    }

    public function getConfirmedBy(): ?User
    {
        return $this->confirmedBy;
    }

    public function setConfirmedBy(?User $confirmedBy): static
    {
        $this->confirmedBy = $confirmedBy;
        return $this;
    }

    public function getConfirmedAt(): \DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function setConfirmedAt(\DateTimeImmutable $confirmedAt): static
    {
        $this->confirmedAt = $confirmedAt;
        return $this;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;
        return $this;
    }
}
