<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Entity\Tenant;
use App\Service\Group\HoldingScope;

/**
 * HoldingScope for unit tests without a database: runs the callback as is.
 * The callback's tenant handling is covered by the kernel tests.
 */
final class PassThroughHoldingScope extends HoldingScope
{
    /** @var list<?int> tenants pinned via asTenant(), in call order */
    public array $pinned = [];

    public function __construct()
    {
    }

    public function read(callable $fn): mixed
    {
        return $fn();
    }

    public function asTenant(Tenant $tenant, callable $fn): mixed
    {
        $this->pinned[] = $tenant->getId();

        return $fn();
    }
}
