<?php

declare(strict_types=1);

namespace App\Tests\Template;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use App\Template\SystemTemplate;
use App\Template\SystemTemplateApplier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class SystemTemplateApplierTest extends TestCase
{
    #[Test]
    public function materialisesSingleRecordTemplateWithTenantBinding(): void
    {
        $tenant = new Tenant();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->isInstanceOf(Risk::class));

        $tc = $this->createMock(TenantContext::class);
        $tc->method('getCurrentTenant')->willReturn($tenant);

        $mc = $this->createMock(ModuleConfigurationService::class);

        $applier = new SystemTemplateApplier($em, $tc, $mc);

        $tpl = new SystemTemplate(
            key: 'risk.test',
            entityClass: Risk::class,
            module: 'risks',
            language: 'de',
            name: 'Test',
            description: 'Test',
            prefill: [
                'title' => 'Phishing',
                'category' => 'Test',
                'description' => 'Test desc',
                'probability' => 2,
                'impact' => 3,
            ],
        );

        $entities = $applier->apply($tpl);

        $this->assertCount(1, $entities);
        $this->assertInstanceOf(Risk::class, $entities[0]);
        $this->assertSame('Phishing', $entities[0]->getTitle());
        $this->assertSame($tenant, $entities[0]->getTenant());
    }

    #[Test]
    public function materialisesBulkTemplateAsManyRecords(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(3))->method('persist');

        $tc = $this->createMock(TenantContext::class);
        $tc->method('getCurrentTenant')->willReturn(new Tenant());

        $applier = new SystemTemplateApplier(
            $em,
            $tc,
            $this->createMock(ModuleConfigurationService::class),
        );

        $tpl = new SystemTemplate(
            key: 'risk.bulk',
            entityClass: Risk::class,
            module: 'risks',
            language: 'de',
            name: 'Bulk',
            description: 'Bulk',
            prefill: ['probability' => 2, 'impact' => 3],
            items: [
                ['title' => 'A', 'category' => 'X', 'description' => 'a'],
                ['title' => 'B', 'category' => 'X', 'description' => 'b'],
                ['title' => 'C', 'category' => 'X', 'description' => 'c'],
            ],
        );

        $entities = $applier->apply($tpl);
        $this->assertCount(3, $entities);
        $this->assertSame(3, $applier->lastResult()['records']);
    }

    #[Test]
    public function dryRunSkipsPersist(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $tc = $this->createMock(TenantContext::class);
        $tc->method('getCurrentTenant')->willReturn(new Tenant());

        $applier = new SystemTemplateApplier(
            $em,
            $tc,
            $this->createMock(ModuleConfigurationService::class),
        );

        $tpl = new SystemTemplate(
            key: 'risk.dry',
            entityClass: Risk::class,
            module: 'risks',
            language: 'de',
            name: 'Dry',
            description: 'Dry',
            prefill: ['title' => 'T', 'category' => 'X', 'description' => 'd', 'probability' => 1, 'impact' => 1],
        );

        $entities = $applier->apply($tpl, dryRun: true);
        $this->assertCount(1, $entities);
    }

    #[Test]
    public function tenantProfileApplyDelegatesToModuleConfig(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $tc = $this->createMock(TenantContext::class);

        $mc = $this->createMock(ModuleConfigurationService::class);
        $mc->expects($this->once())
            ->method('saveActiveModules')
            ->with($this->equalTo(['core', 'assets', 'risks']));

        $applier = new SystemTemplateApplier($em, $tc, $mc);

        $tpl = new SystemTemplate(
            key: 'tenant.profile.kmu.de',
            entityClass: Tenant::class,
            module: null,
            language: 'de',
            name: 'KMU',
            description: 'KMU',
            prefill: [
                'profileKey' => 'kmu',
                'activeModules' => ['core', 'assets', 'risks'],
            ],
        );

        $entities = $applier->apply($tpl);
        $this->assertSame([], $entities);
        $this->assertSame('kmu', $applier->lastResult()['profile_applied']);
    }
}
