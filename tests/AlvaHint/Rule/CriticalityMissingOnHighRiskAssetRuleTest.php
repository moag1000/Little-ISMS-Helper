<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\Asset\CriticalityMissingOnHighRiskAssetRule;
use App\Entity\Asset;
use App\Entity\Risk;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class CriticalityMissingOnHighRiskAssetRuleTest extends TestCase
{
    private CriticalityMissingOnHighRiskAssetRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new CriticalityMissingOnHighRiskAssetRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesWhenCiaIsNullAndLinkedToHighRisk(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getInherentRiskLevel')->willReturn(15);

        $asset = new Asset();
        $reflection = new \ReflectionClass($asset);
        $reflection->getProperty('risks')->setValue($asset, new ArrayCollection([$risk]));

        $this->assertTrue($this->rule->appliesTo($asset, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenCiaIsSet(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getInherentRiskLevel')->willReturn(15);

        $asset = new Asset();
        $asset->setConfidentialityValue(3);
        $reflection = new \ReflectionClass($asset);
        $reflection->getProperty('risks')->setValue($asset, new ArrayCollection([$risk]));

        $this->assertFalse($this->rule->appliesTo($asset, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenRiskIsLow(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getInherentRiskLevel')->willReturn(8);

        $asset = new Asset();
        $reflection = new \ReflectionClass($asset);
        $reflection->getProperty('risks')->setValue($asset, new ArrayCollection([$risk]));

        $this->assertFalse($this->rule->appliesTo($asset, $this->user));
    }

    #[Test]
    public function doesNotApplyToNonAssetEntity(): void
    {
        $this->assertFalse($this->rule->appliesTo(new \stdClass(), $this->user));
    }

    #[Test]
    public function buildReturnsHintWithCorrectKey(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getInherentRiskLevel')->willReturn(15);

        $asset = new Asset();
        $reflection = new \ReflectionClass($asset);
        $reflection->getProperty('risks')->setValue($asset, new ArrayCollection([$risk]));

        $hint = $this->rule->build($asset, $this->user);
        $this->assertSame('asset.criticality_missing_high_risk', $hint->key);
        $this->assertSame(2, $hint->priorityTier);
        $this->assertTrue($hint->dismissible);
    }

    #[Test]
    public function moduleGateIsAssetsAndRisks(): void
    {
        $this->assertSame(['assets', 'risks'], $this->rule->requiredModules());
    }
}
