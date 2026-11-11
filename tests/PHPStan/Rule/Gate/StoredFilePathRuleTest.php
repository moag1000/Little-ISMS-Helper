<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PHPStan\Rules\Rule;
use PHPUnit\Framework\Attributes\Test;

/**
 * Gate 94 (T4): every spelling of the counter-check that joined or opened a stored path is red.
 *
 * @extends GateRuleTestCase<StoredFilePathRule>
 */
final class StoredFilePathRuleTest extends GateRuleTestCase
{
    protected function getRule(): Rule
    {
        return new StoredFilePathRule($this->createReflectionProvider());
    }

    #[Test]
    public function aStoredPathReachesNoFileOrJoin(): void
    {
        $this->assertFixture('src/Service/GateFixture/StoredPaths.php');
    }
}
