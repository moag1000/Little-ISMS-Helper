<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Tenant;
use App\Exception\InvalidArgument\InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class TenantBsiLevelTest extends TestCase
{
    #[Test]
    public function defaultsToStandard(): void
    {
        $tenant = new Tenant();

        $this->assertSame('standard', $tenant->getBsiAssuranceLevel());
    }

    #[Test]
    public function rejectsInvalidLevel(): void
    {
        $tenant = new Tenant();

        $this->expectException(InvalidArgumentException::class);

        $tenant->setBsiAssuranceLevel('gold');
    }

    #[Test]
    public function acceptsValidLevels(): void
    {
        $tenant = new Tenant();

        $tenant->setBsiAssuranceLevel('basis');
        $this->assertSame('basis', $tenant->getBsiAssuranceLevel());

        $tenant->setBsiAssuranceLevel('standard');
        $this->assertSame('standard', $tenant->getBsiAssuranceLevel());

        $tenant->setBsiAssuranceLevel('kern');
        $this->assertSame('kern', $tenant->getBsiAssuranceLevel());
    }
}
