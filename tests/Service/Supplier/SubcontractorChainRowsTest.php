<?php

declare(strict_types=1);

namespace App\Tests\Service\Supplier;

use App\Service\Supplier\SubcontractorChainRows;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SubcontractorChainRowsTest extends TestCase
{
    #[Test]
    public function jsonChainBecomesATreeOfRows(): void
    {
        $rows = SubcontractorChainRows::fromJson([
            ['tier' => 1, 'name' => 'Hosting GmbH', 'country' => 'de', 'criticality' => 'high'],
            ['tier' => 2, 'name' => 'Rechenzentrum AG', 'critical' => true],
            ['tier' => 1, 'name' => ''],
            'Support Ltd',
            ['tier' => 3, 'name' => 'Ohne Elternteil', 'country' => 'Schweiz'],
        ]);

        self::assertSame(['Hosting GmbH', 'Rechenzentrum AG', 'Support Ltd', 'Ohne Elternteil'], array_column($rows, 'name'));
        self::assertSame([2, 3, 2, 3], array_column($rows, 'tier'));
        self::assertSame([null, 0, null, 2], array_column($rows, 'parent'), 'below the nearest entry on a higher level');
        self::assertSame(['critical', 'critical', 'standard', 'standard'], array_column($rows, 'criticality'));
        self::assertSame(['DE', null, null, null], array_column($rows, 'country'), 'only ISO-2 codes fit the column');
    }
}
