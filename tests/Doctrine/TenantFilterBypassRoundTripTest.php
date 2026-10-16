<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Controller\Admin\DataRepairController;
use App\Entity\Risk;
use App\Repository\AssetRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Service\DataIntegrity\EntityCountAggregator;
use App\Service\DataIntegrity\OrphanFinder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Filter\SQLFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Code that reads across organisations bypasses the tenant filter for one
 * query. It must suspend and restore the armed filter: disable()/enable()
 * would hand back a fresh filter without the tenant_id parameter, and the
 * filter skips scoping when that parameter is missing — every later query
 * of the request would then see all organisations.
 */
final class TenantFilterBypassRoundTripTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @return iterable<string, array{callable(ContainerInterface): mixed}>
     */
    public static function bypassingCalls(): iterable
    {
        yield 'orphan finder' => [static fn(ContainerInterface $c) => $c->get(OrphanFinder::class)
            ->findAllOrphanedEntities()];
        yield 'health score' => [static fn(ContainerInterface $c) => $c->get(EntityCountAggregator::class)
            ->calculateHealthScore(0, 0, 0, 0, 0)];
        yield 'orphaned risks' => [static fn(ContainerInterface $c) => $c->get(RiskRepository::class)->findOrphaned()];
        yield 'orphaned assets' => [static fn(ContainerInterface $c) => $c->get(AssetRepository::class)->findOrphaned()];
        yield 'orphaned incidents' => [static fn(ContainerInterface $c) => $c->get(IncidentRepository::class)
            ->findOrphaned()];
        yield 'data repair controller' => [static function (ContainerInterface $c): mixed {
            $method = new \ReflectionMethod(DataRepairController::class, 'withoutTenantFilter');

            return $method->invoke($c->get(DataRepairController::class), static fn(): int => 1);
        }];
    }

    #[Test]
    #[DataProvider('bypassingCalls')]
    public function tenantScopeSurvivesTheBypass(callable $call): void
    {
        $filters = $this->em->getFilters();
        $filter = $filters->isEnabled('tenant_filter')
            ? $filters->getFilter('tenant_filter')
            : $filters->enable('tenant_filter');
        $filter->setParameter('tenant_id', '4711');

        $call(self::getContainer());

        self::assertTrue($filters->isEnabled('tenant_filter'), 'the filter must be armed again');
        self::assertFalse($filters->isSuspended('tenant_filter'));
        $restored = $filters->getFilter('tenant_filter');
        self::assertSame($filter, $restored, 'the armed filter instance must come back');
        self::assertStringContainsString('4711', $this->constraintFor($restored));
    }

    private function constraintFor(SQLFilter $filter): string
    {
        return $filter->addFilterConstraint($this->em->getClassMetadata(Risk::class), 'r');
    }
}
