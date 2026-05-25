<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Department;
use App\Entity\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * S18 B3 — Department entity smoke tests.
 */
class DepartmentTest extends TestCase
{
    #[Test]
    public function testDefaultsAreSensible(): void
    {
        $dept = new Department();

        $this->assertNull($dept->getId());
        $this->assertNull($dept->getName());
        $this->assertNull($dept->getCode());
        $this->assertNull($dept->getDescription());
        $this->assertNull($dept->getParent());
        $this->assertTrue($dept->isActive());
        $this->assertCount(0, $dept->getChildren());
        $this->assertNotNull($dept->getCreatedAt());
    }

    #[Test]
    public function testSettersChainAndExpose(): void
    {
        $tenant = new Tenant();
        $dept = new Department();

        $dept
            ->setTenant($tenant)
            ->setName('IT Security')
            ->setCode('IT-SEC')
            ->setDescription('Information Security Office')
            ->setIsActive(false);

        $this->assertSame($tenant, $dept->getTenant());
        $this->assertSame('IT Security', $dept->getName());
        $this->assertSame('IT-SEC', $dept->getCode());
        $this->assertSame('Information Security Office', $dept->getDescription());
        $this->assertFalse($dept->isActive());
    }

    #[Test]
    public function testToStringRendersNameAndCode(): void
    {
        $dept = new Department();
        $dept->setName('IT');

        $this->assertSame('IT', (string) $dept);

        $dept->setCode('IT-01');
        $this->assertSame('IT (IT-01)', (string) $dept);
    }

    #[Test]
    public function testToStringEmptyWhenNoName(): void
    {
        $dept = new Department();
        $this->assertSame('', (string) $dept);
    }

    #[Test]
    public function testParentChildLink(): void
    {
        $parent = new Department();
        $parent->setName('IT');

        $child = new Department();
        $child->setName('Network Ops');
        $child->setParent($parent);

        $this->assertSame($parent, $child->getParent());
    }
}
