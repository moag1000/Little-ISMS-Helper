<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Tenant;
use App\Repository\AlvaHintRenderCountRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * increment() passed \PDO::PARAM_* ints as parameter types, which DBAL 4
 * rejects with a TypeError — every page that showed an Alva hint crashed
 * while counting the render.
 */
final class AlvaHintRenderCountRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AlvaHintRenderCountRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(AlvaHintRenderCountRepository::class);
        $this->em->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->em->rollback();
        parent::tearDown();
    }

    #[Test]
    public function incrementCountsRendersPerTenant(): void
    {
        $tenant = new Tenant();
        $tenant->setName('Alva Hint Tenant');
        $tenant->setCode('alva_hint_' . bin2hex(random_bytes(4)));
        $this->em->persist($tenant);
        $this->em->flush();

        $this->repository->increment($tenant, 'hint.test');
        $this->repository->increment($tenant, 'hint.test');
        $this->repository->increment($tenant, 'hint.other');

        $totals = $this->repository->totalsByTenant($tenant);
        ksort($totals);

        self::assertSame(['hint.other' => 1, 'hint.test' => 2], $totals);
    }

    #[Test]
    public function incrementAcceptsTheGlobalScope(): void
    {
        $key = 'hint.global.' . bin2hex(random_bytes(4));

        $this->repository->increment(null, $key);

        self::assertSame(1, $this->repository->totalsByTenant(null)[$key] ?? null);
    }
}
