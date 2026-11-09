<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PHPStan\Rules\Rule;
use PHPUnit\Framework\Attributes\Test;

/**
 * Gate 92 (PHP part): Markup, is_safe and PhpWord only reviewed.
 *
 * @extends GateRuleTestCase<MarkupOutputRule>
 */
final class MarkupOutputRuleTest extends GateRuleTestCase
{
    protected function getRule(): Rule
    {
        return new MarkupOutputRule();
    }

    #[Test]
    public function markupNeedsAReviewedReason(): void
    {
        $this->assertFixture('src/Twig/GateFixture/MarkupExtension.php');
    }
}
