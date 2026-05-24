<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\FormRule;

use App\AlvaHint\FormRule\DocumentAcknowledgementWithoutAudienceInlineRule;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentAcknowledgementWithoutAudienceInlineRuleTest extends TestCase
{
    private DocumentAcknowledgementWithoutAudienceInlineRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new DocumentAcknowledgementWithoutAudienceInlineRule();
        $this->user = new User();
    }

    #[Test]
    public function supportsWhenRequiresAcknowledgementAndAudienceEmpty(): void
    {
        self::assertTrue($this->rule->supports([
            'requiresAcknowledgement' => '1',
            'acknowledgementAudience' => [],
        ], $this->user));
        self::assertTrue($this->rule->supports([
            'requiresAcknowledgement' => true,
            'acknowledgementAudience' => '',
        ], $this->user));
    }

    #[Test]
    public function supportsWhenAudienceFieldMissingAndToggleOn(): void
    {
        // Audience input not rendered yet — assume empty.
        self::assertTrue($this->rule->supports(['requiresAcknowledgement' => '1'], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenToggleOff(): void
    {
        self::assertFalse($this->rule->supports([
            'requiresAcknowledgement' => '0',
            'acknowledgementAudience' => [],
        ], $this->user));
        self::assertFalse($this->rule->supports([], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenAudienceIsSelected(): void
    {
        self::assertFalse($this->rule->supports([
            'requiresAcknowledgement' => '1',
            'acknowledgementAudience' => ['42'],
        ], $this->user));
        self::assertFalse($this->rule->supports([
            'requiresAcknowledgement' => '1',
            'acknowledgementAudience' => '42',
        ], $this->user));
    }

    #[Test]
    public function evaluateAnchorsOnAudienceField(): void
    {
        $hint = $this->rule->evaluate([
            'requiresAcknowledgement' => '1',
            'acknowledgementAudience' => [],
        ], $this->user);

        self::assertSame('document.form.acknowledgement_without_audience', $hint->key);
        self::assertSame('acknowledgementAudience', $hint->field);
    }
}
