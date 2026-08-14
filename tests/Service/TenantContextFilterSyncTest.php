<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Filter\SQLFilter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guards the CLI/cron tenant-isolation path.
 *
 * TenantFilterSubscriber only arms the Doctrine tenant_filter on
 * kernel.request. Background work (scheduled reports, Messenger workers,
 * async admin jobs) sets the tenant through TenantContext instead — if that
 * does not push the id into the filter, TenantFilter no-ops and repository
 * calls such as ManagementReportService's findAll() read across ALL tenants.
 */
final class TenantContextFilterSyncTest extends KernelTestCase
{
    #[Test]
    public function settingTenantArmsTheDoctrineFilter(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        $tenantContext = $container->get(TenantContext::class);

        $tenant = new Tenant();
        $reflection = new \ReflectionProperty(Tenant::class, 'id');
        $reflection->setValue($tenant, 4242);

        $tenantContext->setCurrentTenant($tenant);

        $filters = $em->getFilters();
        self::assertTrue($filters->isEnabled('tenant_filter'), 'tenant_filter must stay enabled');

        self::assertSame('4242', self::rawParameter($filters->getFilter('tenant_filter')));
    }

    #[Test]
    public function clearingTenantFallsBackToTheUnscopedSentinel(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        $tenantContext = $container->get(TenantContext::class);

        $tenantContext->setCurrentTenant(null);

        self::assertSame(
            'null',
            self::rawParameter($em->getFilters()->getFilter('tenant_filter')),
            'no tenant means unscoped (admin), matching TenantFilterSubscriber',
        );
    }

    /**
     * SQLFilter::getParameter() quotes through the DB connection, which would
     * make this test require a live database. Read the stored value instead.
     */
    private static function rawParameter(SQLFilter $filter): mixed
    {
        $property = new \ReflectionProperty(SQLFilter::class, 'parameters');
        // ORM 3 stores Query\Filter\Parameter objects; ORM 2 stored raw arrays.
        $stored = $property->getValue($filter)['tenant_id'] ?? null;

        return is_object($stored) ? $stored->value : ($stored['value'] ?? $stored);
    }
}
