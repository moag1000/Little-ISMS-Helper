<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * setParent() refuses cycles, but a cycle written past it (SQL, old data) must not
 * make every tree walk loop until memory runs out — the walks run on every request of
 * a holding (TenantContext::getAccessibleTenants). S31.
 */
final class TenantTreeCycleTest extends TestCase
{
    #[Test]
    public function walksEndAtACycleWrittenPastSetParent(): void
    {
        $a = (new Tenant())->setCode('a');
        $b = (new Tenant())->setCode('b');
        $c = (new Tenant())->setCode('c');
        $a->addSubsidiary($b);
        $b->addSubsidiary($c);
        // c → a closes the ring without setParent()'s check.
        (new \ReflectionProperty(Tenant::class, 'parent'))->setValue($a, $c);
        $c->getSubsidiaries()->add($a);

        self::assertCount(2, $a->getAllSubsidiaries());
        self::assertCount(2, $a->getAllAncestors());
        self::assertSame(2, $a->getHierarchyDepth());
        self::assertTrue($a->isChildOf($b));
        self::assertInstanceOf(Tenant::class, $a->getRootParent());
    }

    #[Test]
    public function aTreeWithoutCycleKeepsItsOrder(): void
    {
        $root = (new Tenant())->setCode('root');
        $child = (new Tenant())->setCode('child');
        $grandChild = (new Tenant())->setCode('grand');
        $root->addSubsidiary($child);
        $child->addSubsidiary($grandChild);

        self::assertSame([$child, $grandChild], $root->getAllSubsidiaries());
        self::assertSame([$child, $root], $grandChild->getAllAncestors());
        self::assertSame($root, $grandChild->getRootParent());
        self::assertSame(0, $root->getHierarchyDepth());
        self::assertTrue($grandChild->isChildOf($root));
        self::assertFalse($root->isChildOf($grandChild));
    }
}
