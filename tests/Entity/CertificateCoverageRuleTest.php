<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CertificateCoverageRule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CertificateCoverageRuleTest extends TestCase
{
    #[Test]
    public function emptyConditionsMatchEverything(): void
    {
        $r = (new CertificateCoverageRule())->setFrameworkCode('ISO27001');
        self::assertTrue($r->matches(null, []));
        self::assertTrue($r->matches('3', ['room-a']));
    }

    #[Test]
    public function classAndTagConditionsAreAnded(): void
    {
        $r = (new CertificateCoverageRule())->setFrameworkCode('EN50600')
            ->setRequiredClass('3')->setRequiredScopeTags(['computer-room']);
        self::assertTrue($r->matches('3', ['computer-room','ups']));
        self::assertFalse($r->matches('2', ['computer-room']));
        self::assertFalse($r->matches('3', ['ups']));
    }
}
