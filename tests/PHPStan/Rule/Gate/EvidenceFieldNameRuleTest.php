<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PHPStan\Rules\Rule;
use PHPUnit\Framework\Attributes\Test;

/**
 * Gate 105 (S34): an evidence-named entity field says who writes it.
 *
 * @extends GateRuleTestCase<EvidenceFieldNameRule>
 */
final class EvidenceFieldNameRuleTest extends GateRuleTestCase
{
    protected function getRule(): Rule
    {
        return new EvidenceFieldNameRule();
    }

    #[Test]
    public function evidenceNamedFieldsNameTheirWriter(): void
    {
        $this->assertFixture('src/Entity/GateFixture/EvidenceFields.php');
    }
}
