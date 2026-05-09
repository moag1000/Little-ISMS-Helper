<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PurgeSandboxWizardRunsCommand;
use App\Entity\WizardRun;
use App\Repository\WizardRunRepository;
use App\Service\PolicyWizard\WizardStepKeys;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Policy-Wizard W2-C — sandbox-purge command tests.
 *
 * Verifies the 7-day cut-off semantics and the dry-run / execute
 * behaviour. The repository is mocked so the test stays decoupled
 * from the schema.
 */
#[AllowMockObjectsWithoutExpectations]
final class PurgeSandboxWizardRunsCommandTest extends TestCase
{
    private MockObject $repository;
    private MockObject $entityManager;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(WizardRunRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
    }

    private function buildTester(): CommandTester
    {
        $command = new PurgeSandboxWizardRunsCommand($this->repository, $this->entityManager);

        $app = new Application();
        $app->addCommand($command);

        return new CommandTester($app->find('app:policy-wizard:purge-sandboxes'));
    }

    private function makeSandboxRun(int $id, DateTimeImmutable $startedAt): WizardRun
    {
        $run = new WizardRun();
        $run->setStatus(WizardStepKeys::STATUS_SANDBOX);
        $run->setMode(WizardStepKeys::MODE_SANDBOX);
        $run->setStartedAt($startedAt);
        $run->setStep(WizardStepKeys::STEP_WELCOME);

        // Forge an id via reflection so the table-render in -v output
        // doesn't choke on null. PHP 8.1+: properties are accessible
        // via Reflection without the deprecated setAccessible() call.
        $ref = new \ReflectionClass($run);
        $ref->getProperty('id')->setValue($run, $id);

        return $run;
    }

    #[Test]
    public function testPurgesOldSandboxRuns(): void
    {
        $tenDaysOld = new DateTimeImmutable('-10 days');
        $eightDaysOld = new DateTimeImmutable('-8 days');
        $expired = [
            $this->makeSandboxRun(1, $tenDaysOld),
            $this->makeSandboxRun(2, $eightDaysOld),
        ];

        $this->repository
            ->expects(self::once())
            ->method('findSandboxOlderThan')
            ->with(self::callback(function (DateTimeImmutable $cutoff): bool {
                $expected = new DateTimeImmutable('-7 days');
                $deltaSeconds = abs($expected->getTimestamp() - $cutoff->getTimestamp());
                return $deltaSeconds < 5; // allow for clock-tick during execution
            }))
            ->willReturn($expired);

        $this->entityManager
            ->expects(self::exactly(2))
            ->method('remove');
        $this->entityManager
            ->expects(self::once())
            ->method('flush');

        $tester = $this->buildTester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Purged 2 sandbox run', $tester->getDisplay());
    }

    #[Test]
    public function testPreservesActiveSandboxesNewerThanSevenDays(): void
    {
        // Repository's findSandboxOlderThan returns nothing → command
        // must short-circuit without remove()/flush() calls.
        $this->repository
            ->expects(self::once())
            ->method('findSandboxOlderThan')
            ->willReturn([]);

        $this->entityManager
            ->expects(self::never())
            ->method('remove');
        $this->entityManager
            ->expects(self::never())
            ->method('flush');

        $tester = $this->buildTester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('No sandbox runs older than the cut-off', $tester->getDisplay());
    }

    #[Test]
    public function testDoesNotPurgeNonSandboxRuns(): void
    {
        // The repository's `findSandboxOlderThan` filter ALREADY scopes
        // by `status='sandbox'` (verified in WizardRunRepositoryTest);
        // here we assert the command never reaches into a different
        // status bucket on its own — it relies entirely on the
        // repository for selection.
        $this->repository
            ->expects(self::once())
            ->method('findSandboxOlderThan')
            ->willReturn([]);

        // Belt-and-suspenders: if a future refactor accidentally pulls
        // findBy()/findAll() the command would surface non-sandbox
        // rows. Assert those paths stay untouched.
        $this->repository
            ->expects(self::never())
            ->method('findBy');
        $this->repository
            ->expects(self::never())
            ->method('findAll');

        $tester = $this->buildTester();
        $exit = $tester->execute([]);

        self::assertSame(0, $exit);
    }

    #[Test]
    public function testDryRunSkipsDeletes(): void
    {
        $expired = [
            $this->makeSandboxRun(1, new DateTimeImmutable('-30 days')),
        ];
        $this->repository
            ->expects(self::once())
            ->method('findSandboxOlderThan')
            ->willReturn($expired);

        $this->entityManager->expects(self::never())->method('remove');
        $this->entityManager->expects(self::never())->method('flush');

        $tester = $this->buildTester();
        $exit = $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('would be deleted', $tester->getDisplay());
    }

    #[Test]
    public function testCustomDaysOverridesDefault(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('findSandboxOlderThan')
            ->with(self::callback(function (DateTimeImmutable $cutoff): bool {
                $expected = new DateTimeImmutable('-14 days');
                return abs($expected->getTimestamp() - $cutoff->getTimestamp()) < 5;
            }))
            ->willReturn([]);

        $tester = $this->buildTester();
        $exit = $tester->execute(['--days' => 14]);

        self::assertSame(0, $exit);
    }

    #[Test]
    public function testInvalidDaysRejected(): void
    {
        $this->repository->expects(self::never())->method('findSandboxOlderThan');

        $tester = $this->buildTester();
        $exit = $tester->execute(['--days' => 0]);

        self::assertSame(1, $exit, '0-day retention must be rejected.');
    }
}
