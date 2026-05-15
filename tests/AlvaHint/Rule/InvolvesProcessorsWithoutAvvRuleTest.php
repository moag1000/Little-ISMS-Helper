<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\ProcessingActivity\InvolvesProcessorsWithoutAvvRule;
use App\Entity\ProcessingActivity;
use App\Entity\Supplier;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class InvolvesProcessorsWithoutAvvRuleTest extends TestCase
{
    private InvolvesProcessorsWithoutAvvRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new InvolvesProcessorsWithoutAvvRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesWhenProcessorsInvolvedAndNoSupplierLinked(): void
    {
        $pa = (new ProcessingActivity())->setInvolvesProcessors(true);

        $this->assertTrue($this->rule->appliesTo($pa, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenInvolvesProcessorsFalse(): void
    {
        $pa = (new ProcessingActivity())->setInvolvesProcessors(false);

        $this->assertFalse($this->rule->appliesTo($pa, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenSupplierLinked(): void
    {
        $pa = (new ProcessingActivity())->setInvolvesProcessors(true);
        $pa->addProcessorSupplier(new Supplier());

        $this->assertFalse($this->rule->appliesTo($pa, $this->user));
    }

    #[Test]
    public function buildEmitsTier2WarningWithAvvPickerRoute(): void
    {
        $pa = (new ProcessingActivity())->setInvolvesProcessors(true);

        $hint = $this->rule->build($pa, $this->user);

        $this->assertSame('processing_activity.involves_processors_without_avv', $hint->key);
        $this->assertSame(2, $hint->priorityTier);
        $this->assertSame('warning', $hint->variant);
        $this->assertSame('app_processing_activity_avv_picker', $hint->actionRoute);
        $this->assertSame('GET', $hint->actionMethod);
        $this->assertSame(['ROLE_DPO'], $hint->requiredRoles);
    }

    #[Test]
    public function isPrivacyModuleGated(): void
    {
        $this->assertSame(['privacy'], $this->rule->requiredModules());
    }
}
