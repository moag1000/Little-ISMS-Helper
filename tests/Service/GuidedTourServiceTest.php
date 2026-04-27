<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\GuidedTourService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unit-Tests für GuidedTourService (Sprint 13).
 */
#[AllowMockObjectsWithoutExpectations]
class GuidedTourServiceTest extends TestCase
{
    private function buildService(array $grantedRoles = []): GuidedTourService
    {
        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')
            ->willReturnCallback(static fn(string $attribute): bool => in_array($attribute, $grantedRoles, true));

        return new GuidedTourService($authChecker);
    }

    #[Test]
    public function testAutoDetectFallsBackToJunior(): void
    {
        $service = $this->buildService([]);
        $this->assertSame('junior', $service->autoDetectTour(new User()));
    }

    #[Test]
    public function testAutoDetectAuditorBeatsAdmin(): void
    {
        // Auditor hat oft zusätzlich ROLE_USER. Die Priorisierung muss
        // Auditor zuverlässig gewinnen lassen.
        $service = $this->buildService(['ROLE_AUDITOR', 'ROLE_ADMIN']);
        $this->assertSame('auditor', $service->autoDetectTour(new User()));
    }

    #[Test]
    public function testAutoDetectRiskOwnerBeforeCm(): void
    {
        $service = $this->buildService(['ROLE_RISK_OWNER', 'ROLE_COMPLIANCE_MANAGER']);
        $this->assertSame('risk_owner', $service->autoDetectTour(new User()));
    }

    #[Test]
    public function testAutoDetectCm(): void
    {
        $service = $this->buildService(['ROLE_COMPLIANCE_MANAGER']);
        $this->assertSame('cm', $service->autoDetectTour(new User()));
    }

    #[Test]
    public function testAutoDetectIsb(): void
    {
        $service = $this->buildService(['ROLE_ISB']);
        $this->assertSame('isb', $service->autoDetectTour(new User()));
    }

    #[Test]
    public function testAutoDetectManagerMapsToIsb(): void
    {
        // Manager ist eine RBAC-Rolle oberhalb USER — die Tour soll
        // den ISB-Flow zeigen (operativ), nicht den CISO-Flow (Exec).
        $service = $this->buildService(['ROLE_MANAGER']);
        $this->assertSame('isb', $service->autoDetectTour(new User()));
    }

    #[Test]
    public function testAutoDetectCisoFromGroupCiso(): void
    {
        $service = $this->buildService(['ROLE_GROUP_CISO']);
        $this->assertSame('ciso', $service->autoDetectTour(new User()));
    }

    #[Test]
    public function testAutoDetectCisoFromAdmin(): void
    {
        $service = $this->buildService(['ROLE_ADMIN']);
        $this->assertSame('ciso', $service->autoDetectTour(new User()));
    }

    #[Test]
    public function testAllToursHaveSteps(): void
    {
        $service = $this->buildService([]);
        foreach (GuidedTourService::ALL_TOURS as $tourId) {
            $steps = $service->stepsFor($tourId);
            $this->assertNotEmpty($steps, "Tour '{$tourId}' must have at least one step");
        }
    }

    #[Test]
    public function testEveryStepHasRequiredKeys(): void
    {
        $service = $this->buildService([]);
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
        $service = $this->buildService([]);
        $this->assertCount(7, $service->stepsFor('junior'));
        $this->assertCount(5, $service->stepsFor('cm'));
        $this->assertCount(4, $service->stepsFor('ciso'));
        $this->assertCount(5, $service->stepsFor('isb'));
        $this->assertCount(2, $service->stepsFor('risk_owner'));
        $this->assertCount(3, $service->stepsFor('auditor'));
        // MRIS-Topic-Tour: 8 Stopps (themenbezogen, nicht rollenbezogen).
        $this->assertCount(8, $service->stepsFor('mris'));
    }

    #[Test]
    public function testMrisSuggestionEligibilityRespectsAuditorExclusion(): void
    {
        // ROLE_USER ohne ROLE_AUDITOR → MRIS-Suggestion erlaubt
        $service = $this->buildService(['ROLE_USER']);
        $this->assertTrue($service->isEligibleForMrisSuggestion());

        // ROLE_USER + ROLE_AUDITOR → keine MRIS-Suggestion (zu viele Re-Suggestions im
        // Read-only-Workflow).
        $service = $this->buildService(['ROLE_USER', 'ROLE_AUDITOR']);
        $this->assertFalse($service->isEligibleForMrisSuggestion());

        // Kein ROLE_USER (nicht eingeloggt) → keine Suggestion
        $service = $this->buildService([]);
        $this->assertFalse($service->isEligibleForMrisSuggestion());
    }

    #[Test]
    public function testUnknownTourReturnsEmpty(): void
    {
        $service = $this->buildService([]);
        $this->assertSame([], $service->stepsFor('wurst'));
    }

    #[Test]
    public function testMetaReflectsStepCount(): void
    {
        $service = $this->buildService([]);
        $meta = $service->metaFor('junior');
        $this->assertSame('junior', $meta['id']);
        $this->assertSame(7, $meta['step_count']);
        $this->assertGreaterThan(0, $meta['duration_min']);
    }

    #[Test]
    public function testAllMetaCoversAllTours(): void
    {
        $service = $this->buildService([]);
        $all = $service->allMeta();
        $this->assertCount(count(GuidedTourService::ALL_TOURS), $all);
        $ids = array_map(static fn(array $m): string => $m['id'], $all);
        foreach (GuidedTourService::ALL_TOURS as $tourId) {
            $this->assertContains($tourId, $ids);
        }
    }
}
