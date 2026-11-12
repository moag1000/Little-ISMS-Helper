<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PHPStan\Rules\Rule;
use PHPUnit\Framework\Attributes\Test;

/**
 * Gate 104 (S33): a page of a record about a person without read rule is red in every spelling of the
 * counter-check — ROLE_USER, a reviewer role, a filter inside the detail page, a route without method list.
 *
 * @extends GateRuleTestCase<PersonalDataReadRule>
 */
final class PersonalDataReadRuleTest extends GateRuleTestCase
{
    protected function getRule(): Rule
    {
        return new PersonalDataReadRule($this->createReflectionProvider());
    }

    #[Test]
    public function pagesOfPersonRecordsCarryAReadRule(): void
    {
        $this->assertFixture('src/Controller/GateFixture/PersonRecordController.php');
    }
}
