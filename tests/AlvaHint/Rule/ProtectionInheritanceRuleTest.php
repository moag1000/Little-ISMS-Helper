<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\Asset\ProtectionInheritanceRule;
use App\Entity\Asset;
use App\Entity\User;
use App\Service\AssetDependencyService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ProtectionInheritanceRuleTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    #[Test]
    public function doesNotApplyWithoutDependencies(): void
    {
        $service = $this->createMock(AssetDependencyService::class);
        $rule = new ProtectionInheritanceRule($service);
        $asset = new Asset();
        $reflection = new \ReflectionClass($asset);
        $reflection->getProperty('dependsOn')->setValue($asset, new ArrayCollection());

        $this->assertFalse($rule->appliesTo($asset, $this->user));
    }

    #[Test]
    public function appliesWhenInheritedHigherThanCurrent(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(2);
        $asset->setIntegrityValue(2);
        $asset->setAvailabilityValue(2);
        $reflection = new \ReflectionClass($asset);
        $other = new Asset();
        $reflection->getProperty('dependsOn')->setValue($asset, new ArrayCollection([$other]));

        $service = $this->createMock(AssetDependencyService::class);
        $service->method('calculateInheritedProtectionNeed')->willReturn([
            'confidentiality' => 4,
            'integrity' => 2,
            'availability' => 3,
            'drivenBy' => ['confidentiality' => null, 'integrity' => null, 'availability' => null],
        ]);
        $rule = new ProtectionInheritanceRule($service);

        $this->assertTrue($rule->appliesTo($asset, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenInheritedEqualsCurrent(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(3);
        $asset->setIntegrityValue(3);
        $asset->setAvailabilityValue(3);
        $reflection = new \ReflectionClass($asset);
        $reflection->getProperty('dependsOn')->setValue($asset, new ArrayCollection([new Asset()]));

        $service = $this->createMock(AssetDependencyService::class);
        $service->method('calculateInheritedProtectionNeed')->willReturn([
            'confidentiality' => 3,
            'integrity' => 3,
            'availability' => 3,
            'drivenBy' => ['confidentiality' => null, 'integrity' => null, 'availability' => null],
        ]);
        $rule = new ProtectionInheritanceRule($service);

        $this->assertFalse($rule->appliesTo($asset, $this->user));
    }
}
