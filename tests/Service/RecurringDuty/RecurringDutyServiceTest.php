<?php

declare(strict_types=1);

namespace App\Tests\Service\RecurringDuty;

use App\Entity\ISMSObjective;
use App\Entity\Tenant;
use App\Repository\RecurringDutyRepository;
use App\Service\ModuleConfigurationService;
use App\Service\RecurringDuty\RecurringDutyService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecurringDutyServiceTest extends TestCase
{
    #[Test]
    public function aDutyAppliesOnlyWithItsModuleActive(): void
    {
        $service = $this->service(['bcm']);
        $tenant = new Tenant();

        self::assertTrue($service->applies('bia_review', $tenant));
        self::assertFalse($service->applies('tom_review', $tenant), 'privacy module off');
        self::assertFalse($service->applies('unknown', $tenant));
    }

    #[Test]
    public function theDefaultIntervalIsTheNorms(): void
    {
        self::assertSame(12, $this->service(['bcm'])->defaultInterval('bia_review', new Tenant()));
    }

    #[Test]
    public function objectivesAreMeasuredPerObjectiveNotAsCatalogueDuty(): void
    {
        // ISO 27001 9.1: one deadline per objective (ObjectiveMeasurementSource), no second reminder.
        self::assertFalse($this->service(['objectives'])->applies('objectives_measurement', new Tenant()));
    }

    /**
     * @param list<string> $activeModules
     * @param list<ISMSObjective> $objectives
     */
    private function service(array $activeModules, array $objectives = []): RecurringDutyService
    {
        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('isModuleActiveForTenant')->willReturnCallback(
            static fn (string $key): bool => in_array($key, $activeModules, true),
        );
        $objectiveRepository = $this->createStub(EntityRepository::class);
        $objectiveRepository->method('findBy')->willReturn($objectives);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($objectiveRepository);

        return new RecurringDutyService($entityManager, $this->createStub(RecurringDutyRepository::class), $modules);
    }
}
