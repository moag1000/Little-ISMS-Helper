<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\GuidedTourService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use App\Tests\Stub\PersonaResolverFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit-Tests für GuidedTourService (Sprint 13).
 */
#[AllowMockObjectsWithoutExpectations]
class GuidedTourServiceTest extends TestCase
{
    private function buildService(): GuidedTourService
    {
        return new GuidedTourService(PersonaResolverFactory::create());
    }

    /** @param list<string> $roles */
    private function user(array $roles): User
    {
        $user = new User();
        $user->setRoles($roles);

        return $user;
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function personaTours(): iterable
    {
        yield 'plain user' => [[], 'junior'];
        yield 'auditor' => [['ROLE_AUDITOR'], 'auditor'];
        yield 'function owner' => [['ROLE_FUNCTION_OWNER'], 'risk_owner'];
        yield 'compliance manager' => [['ROLE_COMPLIANCE_MANAGER'], 'cm'];
        yield 'isb' => [['ROLE_ISB'], 'isb'];
        // Manager is operational: ISB flow, not the CISO (exec) flow.
        yield 'manager' => [['ROLE_MANAGER'], 'isb'];
        // The role hierarchy gives every manager ROLE_AUDITOR — that must not
        // make them auditors (the old first-match bug).
        yield 'isb is no auditor' => [['ROLE_ISB', 'ROLE_AUDITOR'], 'isb'];
        yield 'admin' => [['ROLE_ADMIN'], 'ciso'];
        yield 'top management' => [['ROLE_TOP_MGMT'], 'board'];
        yield 'group ciso' => [['ROLE_GROUP_CISO'], 'board'];
    }

    /** @param list<string> $roles */
    #[Test]
    #[DataProvider('personaTours')]
    public function testAutoDetectFollowsPersona(array $roles, string $tour): void
    {
        $this->assertSame($tour, $this->buildService()->autoDetectTour($this->user($roles)));
    }

    #[Test]
    public function testAllToursHaveSteps(): void
    {
        $service = $this->buildService();
        foreach (GuidedTourService::ALL_TOURS as $tourId) {
            $steps = $service->stepsFor($tourId);
            $this->assertNotEmpty($steps, "Tour '{$tourId}' must have at least one step");
        }
    }

    #[Test]
    public function testEveryStepHasRequiredKeys(): void
    {
        $service = $this->buildService();
        foreach (GuidedTourService::ALL_TOURS as $tourId) {
            foreach ($service->stepsFor($tourId) as $i => $step) {
                $this->assertArrayHasKey('id', $step);
                $this->assertArrayHasKey('title_key', $step);
                $this->assertArrayHasKey('body_key', $step);
                $this->assertArrayHasKey('placement', $step);
                $this->assertIsString($step['title_key'], "Step {$i} of {$tourId} missing title_key");
                $this->assertIsString($step['body_key'], "Step {$i} of {$tourId} missing body_key");
            }
        }
    }

    #[Test]
    public function testStepCountsMatchPlan(): void
    {
        // Plan-Vertrag aus .claude/GUIDED_TOUR_PLAN.md § Tour-Matrix.
        $service = $this->buildService();
        $this->assertCount(7, $service->stepsFor('junior'));
        $this->assertCount(5, $service->stepsFor('cm'));
        $this->assertCount(4, $service->stepsFor('ciso'));
        // 6 ISB stops: welcome, soa, risk, incidents, workflows, audit-log
        // (risk register added — ISO 27001 Cl. 6.1.2 is daily ISB core work).
        $this->assertCount(6, $service->stepsFor('isb'));
        $this->assertCount(2, $service->stepsFor('risk_owner'));
        $this->assertCount(4, $service->stepsFor('auditor'));
        $this->assertCount(4, $service->stepsFor('board'));
        // MRIS-Topic-Tour: 8 Stopps (themenbezogen, nicht rollenbezogen).
        $this->assertCount(8, $service->stepsFor('mris'));
    }

    #[Test]
    public function testMrisSuggestionEligibilityRespectsReadingPersonas(): void
    {
        $service = $this->buildService();
        $this->assertTrue($service->isEligibleForMrisSuggestion($this->user([])));
        // Managers inherit ROLE_AUDITOR but work on the ISMS — they get it.
        $this->assertTrue($service->isEligibleForMrisSuggestion($this->user(['ROLE_ISB'])));
        $this->assertFalse($service->isEligibleForMrisSuggestion($this->user(['ROLE_AUDITOR'])));
        $this->assertFalse($service->isEligibleForMrisSuggestion($this->user(['ROLE_TOP_MGMT'])));
    }

    #[Test]
    public function testUnknownTourReturnsEmpty(): void
    {
        $service = $this->buildService();
        $this->assertSame([], $service->stepsFor('wurst'));
    }

    #[Test]
    public function testMetaReflectsStepCount(): void
    {
        $service = $this->buildService();
        $meta = $service->metaFor('junior');
        $this->assertSame('junior', $meta['id']);
        $this->assertSame(7, $meta['step_count']);
        $this->assertGreaterThan(0, $meta['duration_min']);
    }

    #[Test]
    public function testAllMetaCoversAllTours(): void
    {
        $service = $this->buildService();
        $all = $service->allMeta();
        $this->assertCount(count(GuidedTourService::ALL_TOURS), $all);
        $ids = array_map(static fn(array $m): string => $m['id'], $all);
        foreach (GuidedTourService::ALL_TOURS as $tourId) {
            $this->assertContains($tourId, $ids);
        }
    }
}
