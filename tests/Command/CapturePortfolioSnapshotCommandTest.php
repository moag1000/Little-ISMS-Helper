<?php

namespace App\Tests\Command;

use App\Entity\PortfolioSnapshot;
use App\Entity\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Functional tests for app:portfolio:capture-snapshot (CM-3).
 *
 * The key property under test is idempotency: running the command twice on
 * the same day must not create duplicate rows — the existsForDate() guard
 * has to skip tenants that already have a snapshot set.
 */
class CapturePortfolioSnapshotCommandTest extends KernelTestCase
{
    private CommandTester $commandTester;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $application = new Application($kernel);
        $command = $application->find('app:portfolio:capture-snapshot');
        $this->commandTester = new CommandTester($command);

        $uniqueId = uniqid('portfolio_', true);
        $this->testTenant = new Tenant();
        $this->testTenant->setName('Portfolio Test ' . $uniqueId);
        $this->testTenant->setCode('pf_' . $uniqueId);
        $this->entityManager->persist($this->testTenant);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        // Purge any snapshots created by the test tenant.
        if ($this->testTenant !== null) {
            try {
                $this->entityManager->createQueryBuilder()
                    ->delete(PortfolioSnapshot::class, 's')
                    ->where('s.tenant = :tenant')
                    ->setParameter('tenant', $this->testTenant)
                    ->getQuery()
                    ->execute();

                $managed = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($managed !== null) {
                    $this->entityManager->remove($managed);
                    $this->entityManager->flush();
                }
            } catch (\Throwable) {
                // Keep teardown best-effort; the test DB is rebuilt by doctrine:migrations:migrate.
            }
        }

        parent::tearDown();
    }

    public function testCommandHasCorrectName(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $command = $application->find('app:portfolio:capture-snapshot');
        $this->assertSame('app:portfolio:capture-snapshot', $command->getName());
    }

    public function testDryRunDoesNotWrite(): void
    {
        $this->commandTester->execute([
            '--dry-run' => true,
            '--tenant' => $this->testTenant->getCode(),
        ]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('DRY RUN', $this->commandTester->getDisplay());

        $countAfter = $this->countSnapshots();
        $this->assertSame(0, $countAfter, 'Dry-run must not persist snapshots');
    }

    public function testIdempotentOnSameDay(): void
    {
        // First run — should write one row per (framework, category).
        $this->commandTester->execute([
            '--tenant' => $this->testTenant->getCode(),
        ]);
        $this->assertSame(0, $this->commandTester->getStatusCode());
        $firstRunCount = $this->countSnapshots();

        // Clear EM so the second run's existsForDate-check sees committed rows.
        $this->entityManager->clear();

        // Second run — must be a no-op.
        $this->commandTester->execute([
            '--tenant' => $this->testTenant->getCode(),
        ]);
        $this->assertSame(0, $this->commandTester->getStatusCode());
        $this->assertStringContainsString('SKIP', $this->commandTester->getDisplay());

        $secondRunCount = $this->countSnapshots();
        $this->assertSame(
            $firstRunCount,
            $secondRunCount,
            'Second run on same day must not add rows',
        );
    }

    private function countSnapshots(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(PortfolioSnapshot::class, 's')
            ->where('s.tenant = :tenant')
            ->setParameter('tenant', $this->testTenant)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
