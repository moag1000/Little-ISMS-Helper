<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CertificateCoverageRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * CertificateCoverageRule
 *
 * Maps a certificate class / scope-tag combination to the set of requirement-ids
 * it covers for a given framework. A resolver picks matching rules and unions
 * their requirementIds to bulk-fulfil controls.
 */
#[ORM\Entity(repositoryClass: CertificateCoverageRuleRepository::class)]
#[ORM\Table(name: 'certificate_coverage_rule')]
#[ORM\Index(name: 'idx_ccr_framework', columns: ['framework_code'])]
#[ORM\Index(name: 'idx_ccr_active', columns: ['active'])]
class CertificateCoverageRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Framework code this rule applies to, e.g. "ISO27001", "EN50600".
     */
    #[ORM\Column(name: 'framework_code', length: 100)]
    private string $frameworkCode = '';

    /**
     * If set, the certificate must have this exact class/level to match.
     * Null means "any class matches".
     */
    #[ORM\Column(name: 'required_class', length: 100, nullable: true)]
    private ?string $requiredClass = null;

    /**
     * Scope tags that must ALL be present on the certificate for this rule to match.
     * Empty array means "no tag restriction".
     *
     * @var array<int|string, mixed>
     */
    #[ORM\Column(name: 'required_scope_tags', type: Types::JSON)]
    private array $requiredScopeTags = [];

    /**
     * The compliance requirement-ids this rule covers when matched.
     *
     * @var array<int|string, mixed>
     */
    #[ORM\Column(name: 'requirement_ids', type: Types::JSON)]
    private array $requirementIds = [];

    /**
     * Default fulfillment percentage applied to each matched requirement (0–100).
     */
    #[ORM\Column(name: 'default_percentage')]
    private int $defaultPercentage = 100;

    /**
     * Whether this rule is currently active.
     */
    #[ORM\Column(name: 'active')]
    private bool $active = true;

    // ── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFrameworkCode(): string
    {
        return $this->frameworkCode;
    }

    public function setFrameworkCode(string $frameworkCode): static
    {
        $this->frameworkCode = $frameworkCode;
        return $this;
    }

    public function getRequiredClass(): ?string
    {
        return $this->requiredClass;
    }

    public function setRequiredClass(?string $requiredClass): static
    {
        $this->requiredClass = $requiredClass;
        return $this;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getRequiredScopeTags(): array
    {
        return $this->requiredScopeTags;
    }

    /**
     * @param array<int|string, mixed> $requiredScopeTags
     */
    public function setRequiredScopeTags(array $requiredScopeTags): static
    {
        $this->requiredScopeTags = $requiredScopeTags;
        return $this;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getRequirementIds(): array
    {
        return $this->requirementIds;
    }

    /**
     * @param array<int|string, mixed> $requirementIds
     */
    public function setRequirementIds(array $requirementIds): static
    {
        $this->requirementIds = $requirementIds;
        return $this;
    }

    public function getDefaultPercentage(): int
    {
        return $this->defaultPercentage;
    }

    public function setDefaultPercentage(int $defaultPercentage): static
    {
        $this->defaultPercentage = $defaultPercentage;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;
        return $this;
    }

    // ── Domain logic ─────────────────────────────────────────────────────────

    /**
     * Returns true when the given certificate class and scope tags satisfy this rule.
     *
     * - If requiredClass is set, $class must match exactly.
     * - All entries in requiredScopeTags must be present in $scopeTags (subset check).
     * - An empty requiredClass (null) and empty requiredScopeTags match anything.
     *
     * @param array<int|string, mixed> $scopeTags
     */
    public function matches(?string $class, array $scopeTags): bool
    {
        if ($this->requiredClass !== null && $this->requiredClass !== $class) {
            return false;
        }
        // requiredScopeTags must be a subset of the given scopeTags
        foreach ($this->requiredScopeTags as $tag) {
            if (!in_array($tag, $scopeTags, true)) {
                return false;
            }
        }
        return true;
    }
}
