<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AssetDependencyCriticalityImpact;
use App\Enum\AssetDependencyType;
use App\Repository\AssetDependencyRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * AssetDependency — enriched directed Asset → Asset edge (DORA RT_05).
 *
 * Bucket-6 / DORA RoI Sprint 9 (RT_05 asset-dependency-graph): this join
 * entity sits alongside the legacy {@see Asset::$dependsOn} ManyToMany
 * (kept intact for BSI 3.6 Schutzbedarfsvererbung / GstoolXmlImporter
 * backward-compatibility) and carries the per-edge attributes that the
 * ESA RoI XBRL exporter needs to emit per-edge sub-elements:
 *
 *  - `dependencyType`: requires | backs_up | shares_data | redundant_with
 *  - `criticalityImpact`: cascade | isolated | partial
 *
 * Multi-tenancy: enforced via the join-side {@see $sourceAsset} and
 * {@see $targetAsset}, both of which already carry tenant_id. A unique
 * index `(source_asset_id, target_asset_id)` prevents duplicate edges
 * with conflicting metadata.
 */
#[ORM\Entity(repositoryClass: AssetDependencyRepository::class)]
#[ORM\Table(name: 'asset_dependency')]
#[ORM\UniqueConstraint(name: 'uniq_asset_dependency_edge', columns: ['source_asset_id', 'target_asset_id'])]
#[ORM\Index(name: 'idx_asset_dependency_source', columns: ['source_asset_id'])]
#[ORM\Index(name: 'idx_asset_dependency_target', columns: ['target_asset_id'])]
class AssetDependency
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The asset that depends on `$targetAsset` (downstream side).
     */
    #[ORM\ManyToOne(targetEntity: Asset::class)]
    #[ORM\JoinColumn(name: 'source_asset_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Asset $sourceAsset = null;

    /**
     * The asset that `$sourceAsset` depends on (upstream side).
     */
    #[ORM\ManyToOne(targetEntity: Asset::class)]
    #[ORM\JoinColumn(name: 'target_asset_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Asset $targetAsset = null;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: AssetDependencyType::class)]
    #[Assert\NotNull]
    private AssetDependencyType $dependencyType = AssetDependencyType::Requires;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: AssetDependencyCriticalityImpact::class)]
    #[Assert\NotNull]
    private AssetDependencyCriticalityImpact $criticalityImpact = AssetDependencyCriticalityImpact::Cascade;

    /**
     * Optional free-text description (e.g. "DB connection via VPN").
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourceAsset(): ?Asset
    {
        return $this->sourceAsset;
    }

    public function setSourceAsset(?Asset $sourceAsset): static
    {
        $this->sourceAsset = $sourceAsset;
        return $this;
    }

    public function getTargetAsset(): ?Asset
    {
        return $this->targetAsset;
    }

    public function setTargetAsset(?Asset $targetAsset): static
    {
        $this->targetAsset = $targetAsset;
        return $this;
    }

    public function getDependencyType(): AssetDependencyType
    {
        return $this->dependencyType;
    }

    public function setDependencyType(AssetDependencyType $dependencyType): static
    {
        $this->dependencyType = $dependencyType;
        return $this;
    }

    public function getCriticalityImpact(): AssetDependencyCriticalityImpact
    {
        return $this->criticalityImpact;
    }

    public function setCriticalityImpact(AssetDependencyCriticalityImpact $criticalityImpact): static
    {
        $this->criticalityImpact = $criticalityImpact;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
