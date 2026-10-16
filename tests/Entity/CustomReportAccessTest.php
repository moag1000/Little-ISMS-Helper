<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CustomReport;
use App\Entity\Tenant;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CustomReport::canAccess() never grants across organisations — a shared
 * template used to be readable by every organisation.
 */
final class CustomReportAccessTest extends TestCase
{
    #[Test]
    public function sharedTemplateIsOnlyAccessibleInsideItsOrganisation(): void
    {
        $report = $this->report(10, $this->user(1, 10));
        $report->setIsTemplate(true);
        $report->setIsShared(true);

        self::assertTrue($report->canAccess($this->user(2, 10)));
        self::assertFalse($report->canAccess($this->user(3, 20)));
    }

    #[Test]
    public function sharingAndOwnershipRespectTheActingOrganisation(): void
    {
        $owner = $this->user(1, 10);
        $report = $this->report(10, $owner);
        $report->setIsShared(true);
        $report->setSharedWith([2]);

        self::assertTrue($report->canAccess($owner));
        self::assertTrue($report->canAccess($this->user(2, 10)));
        self::assertFalse($report->canAccess($this->user(4, 10)), 'not shared with this colleague');
        self::assertFalse($report->canAccess($owner, 20), 'acting in another organisation');
        self::assertFalse($this->report(null, $owner)->canAccess($owner), 'reports without organisation are inaccessible');
    }

    private function report(?int $tenantId, User $owner): CustomReport
    {
        $report = new CustomReport();
        $report->setTenantId($tenantId);
        $report->setOwner($owner);

        return $report;
    }

    private function user(int $id, int $tenantId): User
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($tenantId);
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getTenant')->willReturn($tenant);

        return $user;
    }
}
