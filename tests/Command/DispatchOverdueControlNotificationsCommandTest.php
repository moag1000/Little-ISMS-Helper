<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\DispatchOverdueControlNotificationsCommand;
use App\Entity\Control;
use App\Entity\Tenant;
use App\Repository\ControlRepository;
use App\Service\Notification\Event\DomainEventNotifier;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
final class DispatchOverdueControlNotificationsCommandTest extends TestCase
{
    #[Test]
    public function firesControlOverdueForTenantedControlsOnly(): void
    {
        $tenant = new Tenant();
        $withTenant = (new Control())->setTenant($tenant)->setControlId('A.5.1')
            ->setNextReviewDate(new \DateTimeImmutable('-2 hours'));
        $orphan = (new Control())->setControlId('A.5.2')
            ->setNextReviewDate(new \DateTimeImmutable('-3 hours')); // no tenant → skipped

        $repo = $this->createMock(ControlRepository::class);
        $repo->method('findBecameOverdueBetween')->willReturn([$withTenant, $orphan]);

        $notifier = $this->createMock(DomainEventNotifier::class);
        $notifier->expects(self::once())->method('fire')
            ->with('control.overdue', $tenant, self::anything());

        $tester = $this->runCommand($repo, $notifier);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Fired 1', $tester->getDisplay());
    }

    #[Test]
    public function dryRunDispatchesNothing(): void
    {
        $repo = $this->createMock(ControlRepository::class);
        $repo->method('findBecameOverdueBetween')->willReturn([
            (new Control())->setTenant(new Tenant())->setControlId('A.5.1')
                ->setNextReviewDate(new \DateTimeImmutable('-1 hour')),
        ]);

        $notifier = $this->createMock(DomainEventNotifier::class);
        $notifier->expects(self::never())->method('fire');

        $tester = $this->runCommand($repo, $notifier, ['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Would fire 1', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(ControlRepository $repo, DomainEventNotifier $notifier, array $input = []): CommandTester
    {
        $tester = new CommandTester(new DispatchOverdueControlNotificationsCommand($repo, $notifier));
        $tester->execute($input);

        return $tester;
    }
}
