<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\Document\RequiresAcknowledgementWithoutAudienceRule;
use App\Entity\Document;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RequiresAcknowledgementWithoutAudienceRuleTest extends TestCase
{
    private RequiresAcknowledgementWithoutAudienceRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new RequiresAcknowledgementWithoutAudienceRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesWhenRequiresAcknowledgementAndEmptyAudience(): void
    {
        $doc = (new Document())->setRequiresAcknowledgement(true);

        $this->assertTrue($this->rule->appliesTo($doc, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenRequiresAcknowledgementFalse(): void
    {
        $doc = (new Document())->setRequiresAcknowledgement(false);

        $this->assertFalse($this->rule->appliesTo($doc, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenAudienceAlreadyPopulated(): void
    {
        $doc = (new Document())->setRequiresAcknowledgement(true);
        $doc->addAcknowledgementAudience(new User());

        $this->assertFalse($this->rule->appliesTo($doc, $this->user));
    }

    #[Test]
    public function buildEmitsTier2InfoHintWithAudiencePickerRoute(): void
    {
        $doc = (new Document())->setRequiresAcknowledgement(true);

        $hint = $this->rule->build($doc, $this->user);

        $this->assertSame('document.requires_acknowledgement_without_audience', $hint->key);
        $this->assertSame(2, $hint->priorityTier);
        $this->assertSame('info', $hint->variant);
        $this->assertSame('app_document_acknowledgement_audience_picker', $hint->actionRoute);
        $this->assertSame('GET', $hint->actionMethod);
        $this->assertSame(['ROLE_MANAGER'], $hint->requiredRoles);
    }

    #[Test]
    public function isNotModuleGated(): void
    {
        $this->assertSame([], $this->rule->requiredModules());
    }
}
