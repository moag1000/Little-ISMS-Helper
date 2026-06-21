<?php

declare(strict_types=1);

namespace App\Tests\Smoke\Planning;

use App\Entity\PlanningSettings;
use App\Entity\Tenant;
use App\Service\Planning\CapacityService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Smoke test for the H-1 divide-by-zero guard in CapacityService.
 *
 * A pre-existing PlanningSettings row may carry an invalid hoursPerDay = 0
 * (the settings form rejects <= 0 only for *new* input, not stored rows).
 * fullTimePtPerWeek() must fall back to the 40/8 = 5 default rather than
 * raising DivisionByZeroError.
 */
final class PlanningCapacityServiceSmokeTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CapacityService $capacityService;
    private ?Tenant $tenant = null;
    private ?PlanningSettings $settings = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->capacityService = $container->get(CapacityService::class);

        $uniqueId = uniqid('cap_', true);
        $this->tenant = new Tenant();
        $this->tenant->setName('Cap Tenant ' . $uniqueId);
        $this->tenant->setCode('cap_' . $uniqueId);
        $this->entityManager->persist($this->tenant);

        $this->settings = new PlanningSettings();
        $this->settings->setTenant($this->tenant);
        $this->settings->setFullTimeHoursPerWeek(40.0);
        $this->settings->setHoursPerDay(0.0); // invalid stored value — the guard target
        $this->entityManager->persist($this->settings);

        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        foreach ([$this->settings, $this->tenant] as $entity) {
            if ($entity === null) {
                continue;
            }
            try {
                $managed = $this->entityManager->find($entity::class, $entity->getId());
                if ($managed) {
                    $this->entityManager->remove($managed);
                }
            } catch (\Exception) {
            }
        }
        try {
            $this->entityManager->flush();
        } catch (\Exception) {
        }

        parent::tearDown();
    }

    #[Test]
    public function fullTimePtPerWeekGuardsAgainstZeroHoursPerDay(): void
    {
        // hoursPerDay=0 stored — must NOT throw and must fall back to 40/8 = 5.0
        $result = $this->capacityService->fullTimePtPerWeek($this->tenant);

        self::assertSame(5.0, $result);
    }
}
