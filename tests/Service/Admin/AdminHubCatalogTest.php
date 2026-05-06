<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Service\Admin\AdminHubCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AdminHubCatalogTest extends TestCase
{
    private AdminHubCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new AdminHubCatalog();
    }

    #[Test]
    public function returnsSevenIaGroups(): void
    {
        $groups = $this->catalog->getGroups();

        $this->assertCount(7, $groups);
        $this->assertSame(
            ['organisation', 'identity', 'isms_data', 'audit_compliance', 'integrations', 'system', 'branding_ux'],
            array_map(static fn(array $group): string => $group['key'], $groups),
        );
    }

    #[Test]
    public function everyGroupCarriesToneAndModules(): void
    {
        foreach ($this->catalog->getGroups() as $group) {
            $this->assertContains(
                $group['tone'],
                ['cyan', 'pink', 'purple'],
                sprintf('Group %s has unexpected tone %s', $group['key'], $group['tone']),
            );
            $this->assertNotEmpty($group['modules'], sprintf('Group %s has no modules', $group['key']));
        }
    }

    #[Test]
    public function moduleEntriesCarryRequiredFields(): void
    {
        foreach ($this->catalog->getGroups() as $group) {
            foreach ($group['modules'] as $module) {
                $this->assertArrayHasKey('key', $module);
                $this->assertArrayHasKey('icon', $module);
                $this->assertArrayHasKey('label', $module);
                $this->assertArrayHasKey('description', $module);
                $this->assertArrayHasKey('route', $module);
                $this->assertNotEmpty($module['key']);
                $this->assertNotEmpty($module['icon']);
            }
        }
    }

    #[Test]
    public function moduleKeysAreGloballyUnique(): void
    {
        $keys = [];
        foreach ($this->catalog->getGroups() as $group) {
            foreach ($group['modules'] as $module) {
                $keys[] = $module['key'];
            }
        }
        $this->assertSame(count($keys), count(array_unique($keys)), 'Duplicate module keys: ' . implode(', ', array_diff_assoc($keys, array_unique($keys))));
    }

    #[Test]
    public function totalsAroundDocumentedSize(): void
    {
        $count = 0;
        foreach ($this->catalog->getGroups() as $group) {
            $count += count($group['modules']);
        }
        // Documented intent: ~36 modules across 7 groups.
        $this->assertGreaterThanOrEqual(30, $count);
        $this->assertLessThanOrEqual(45, $count);
    }
}
