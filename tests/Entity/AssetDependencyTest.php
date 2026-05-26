<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Asset;
use App\Entity\AssetDependency;
use App\Enum\AssetDependencyCriticalityImpact;
use App\Enum\AssetDependencyType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AssetDependency (DORA RT_05 asset-dependency-graph join entity).
 *
 * Bucket-6 close — verifies the entity surface (source/target asset getters,
 * enum-backed dependency-type + criticality-impact, free-text notes,
 * created-at timestamp) so the DoraRoiXbrlExporter contract stays
 * regression-protected.
 */
final class AssetDependencyTest extends TestCase
{
    #[Test]
    public function defaultsAreRequiresAndCascade(): void
    {
        $dep = new AssetDependency();
        self::assertSame(AssetDependencyType::Requires, $dep->getDependencyType());
        self::assertSame(AssetDependencyCriticalityImpact::Cascade, $dep->getCriticalityImpact());
        self::assertNull($dep->getNotes());
        self::assertNull($dep->getId());
    }

    #[Test]
    public function createdAtIsAssignedOnConstruction(): void
    {
        $before = new \DateTimeImmutable();
        $dep = new AssetDependency();
        $after = new \DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $dep->getCreatedAt());
        self::assertLessThanOrEqual($after, $dep->getCreatedAt());
    }

    #[Test]
    public function sourceAndTargetAssetsRoundtrip(): void
    {
        $source = new Asset();
        $source->setName('App');
        $target = new Asset();
        $target->setName('Database');

        $dep = new AssetDependency();
        $dep->setSourceAsset($source);
        $dep->setTargetAsset($target);

        self::assertSame($source, $dep->getSourceAsset());
        self::assertSame($target, $dep->getTargetAsset());
    }

    #[Test]
    public function dependencyTypeAndCriticalityImpactRoundtrip(): void
    {
        $dep = new AssetDependency();
        $dep->setDependencyType(AssetDependencyType::BacksUp);
        $dep->setCriticalityImpact(AssetDependencyCriticalityImpact::Isolated);

        self::assertSame(AssetDependencyType::BacksUp, $dep->getDependencyType());
        self::assertSame(AssetDependencyCriticalityImpact::Isolated, $dep->getCriticalityImpact());
    }

    #[Test]
    public function notesAreOptional(): void
    {
        $dep = new AssetDependency();
        $dep->setNotes('Multicast UDP feed via NIC2');
        self::assertSame('Multicast UDP feed via NIC2', $dep->getNotes());

        $dep->setNotes(null);
        self::assertNull($dep->getNotes());
    }

    #[Test]
    public function allDependencyTypeEnumLabelsResolveToTranslationKey(): void
    {
        foreach (AssetDependencyType::cases() as $case) {
            self::assertStringStartsWith('asset.dependency.type.', $case->label());
        }
    }

    #[Test]
    public function allCriticalityImpactEnumLabelsResolveToTranslationKey(): void
    {
        foreach (AssetDependencyCriticalityImpact::cases() as $case) {
            self::assertStringStartsWith('asset.dependency.criticality_impact.', $case->label());
        }
    }
}
