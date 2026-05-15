<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\Risk\RequiresDpiaWithoutDpiaRule;
use App\Entity\Asset;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\Risk;
use App\Entity\User;
use App\Repository\DataProtectionImpactAssessmentRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RequiresDpiaWithoutDpiaRuleTest extends TestCase
{
    private DataProtectionImpactAssessmentRepository $dpiaRepo;
    private RequiresDpiaWithoutDpiaRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->dpiaRepo = $this->createMock(DataProtectionImpactAssessmentRepository::class);
        $this->rule = new RequiresDpiaWithoutDpiaRule($this->dpiaRepo);
        $this->user = new User();
    }

    #[Test]
    public function appliesWhenRequiresDpiaTrueAndNoAssetLink(): void
    {
        $risk = (new Risk())->setRequiresDPIA(true);

        $this->assertTrue($this->rule->appliesTo($risk, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenRequiresDpiaFalse(): void
    {
        $risk = (new Risk())->setRequiresDPIA(false);

        $this->assertFalse($this->rule->appliesTo($risk, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenDpiaAlreadyLinkedViaAsset(): void
    {
        $asset = new Asset();
        $risk = (new Risk())->setRequiresDPIA(true)->setAsset($asset);
        $existingDpia = new DataProtectionImpactAssessment();

        $this->dpiaRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['relatedAsset' => $asset])
            ->willReturn($existingDpia);

        $this->assertFalse($this->rule->appliesTo($risk, $this->user));
    }

    #[Test]
    public function appliesWhenAssetLinkedButNoDpiaReferencesIt(): void
    {
        $asset = new Asset();
        $risk = (new Risk())->setRequiresDPIA(true)->setAsset($asset);

        $this->dpiaRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['relatedAsset' => $asset])
            ->willReturn(null);

        $this->assertTrue($this->rule->appliesTo($risk, $this->user));
    }

    #[Test]
    public function buildEmitsTier2WarningWithGetMethodAndPreFillParam(): void
    {
        $risk = (new Risk())->setRequiresDPIA(true);
        // Risk has no setter for id; rule reads getId() which is null — OK.

        $hint = $this->rule->build($risk, $this->user);

        $this->assertSame('risk.requires_dpia_without_dpia', $hint->key);
        $this->assertSame(2, $hint->priorityTier);
        $this->assertSame('warning', $hint->variant);
        $this->assertTrue($hint->dismissible);
        $this->assertSame('app_dpia_new', $hint->actionRoute);
        $this->assertArrayHasKey('from_risk', $hint->actionRouteParams);
        $this->assertSame('GET', $hint->actionMethod);
        $this->assertSame(['ROLE_DPO'], $hint->requiredRoles);
    }

    #[Test]
    public function isPrivacyModuleGated(): void
    {
        $this->assertSame(['privacy'], $this->rule->requiredModules());
    }
}
