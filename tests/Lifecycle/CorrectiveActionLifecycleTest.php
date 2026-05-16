<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\CorrectiveAction;
use App\Entity\User;
use App\Lifecycle\InvalidTransitionException;
use App\Lifecycle\LifecycleRegistry;
use App\Lifecycle\LifecycleService;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * S3 P0-30/31/32 — CAPA lifecycle transitions + evidence guard.
 */
#[AllowMockObjectsWithoutExpectations]
class CorrectiveActionLifecycleTest extends TestCase
{
    #[Test]
    public function capaStageTableExposesExpectedTransitions(): void
    {
        $this->assertSame(
            ['in_progress', 'cancelled'],
            LifecycleRegistry::allowedTransitions(LifecycleRegistry::CAPA_STAGES, 'planned'),
        );
        $this->assertSame(
            ['verified_effective', 'verified_ineffective'],
            LifecycleRegistry::allowedTransitions(LifecycleRegistry::CAPA_STAGES, 'completed'),
        );
        $this->assertTrue(
            LifecycleRegistry::isTerminal(LifecycleRegistry::CAPA_STAGES, 'verified_effective'),
        );
        $this->assertTrue(
            LifecycleRegistry::isTerminal(LifecycleRegistry::CAPA_STAGES, 'verified_ineffective'),
        );
    }

    #[Test]
    public function transitionToVerifiedEffectiveWithoutEvidenceThrows(): void
    {
        $service = $this->buildService();
        $action = $this->capaInStatus(CorrectiveAction::STATUS_COMPLETED);
        $user = new User();

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('Effectiveness evidence is required');

        $service->transitionCapa($action, CorrectiveAction::STATUS_VERIFIED_EFFECTIVE, $user, []);
    }

    #[Test]
    public function transitionToVerifiedEffectiveWithEvidenceSucceeds(): void
    {
        $service = $this->buildService();
        $action = $this->capaInStatus(CorrectiveAction::STATUS_COMPLETED);
        $user = new User();

        $service->transitionCapa(
            $action,
            CorrectiveAction::STATUS_VERIFIED_EFFECTIVE,
            $user,
            ['effectivenessEvidence' => 'Re-Audit-Report Q3-2026'],
        );

        $this->assertSame(CorrectiveAction::STATUS_VERIFIED_EFFECTIVE, $action->getStatus());
        $this->assertSame($user, $action->getVerifiedBy());
        $this->assertNotNull($action->getVerifiedAt());
        $this->assertSame('Re-Audit-Report Q3-2026', $action->getEffectivenessEvidence());
    }

    #[Test]
    public function transitionToVerifiedIneffectiveWithEvidenceSucceeds(): void
    {
        $service = $this->buildService();
        $action = $this->capaInStatus(CorrectiveAction::STATUS_COMPLETED);
        $user = new User();

        $service->transitionCapa(
            $action,
            CorrectiveAction::STATUS_VERIFIED_INEFFECTIVE,
            $user,
            ['effectivenessEvidence' => 'Re-Test failed — vulnerability still present'],
        );

        $this->assertSame(CorrectiveAction::STATUS_VERIFIED_INEFFECTIVE, $action->getStatus());
        $this->assertTrue($action->isVerifiedIneffective());
        $this->assertSame('Re-Test failed — vulnerability still present', $action->getEffectivenessEvidence());
    }

    #[Test]
    public function illegalTransitionFromPlannedToVerifiedThrows(): void
    {
        $service = $this->buildService();
        $action = $this->capaInStatus(CorrectiveAction::STATUS_PLANNED);

        $this->expectException(InvalidTransitionException::class);
        $this->expectExceptionMessage('not allowed');

        $service->transitionCapa(
            $action,
            CorrectiveAction::STATUS_VERIFIED_EFFECTIVE,
            new User(),
            ['effectivenessEvidence' => 'irrelevant — should fail earlier'],
        );
    }

    #[Test]
    public function emptyEvidenceStringStillFailsTheGuard(): void
    {
        $service = $this->buildService();
        $action = $this->capaInStatus(CorrectiveAction::STATUS_COMPLETED);

        $this->expectException(InvalidTransitionException::class);

        $service->transitionCapa(
            $action,
            CorrectiveAction::STATUS_VERIFIED_EFFECTIVE,
            new User(),
            ['effectivenessEvidence' => '   '],
        );
    }

    private function buildService(): LifecycleService
    {
        return new LifecycleService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AuditLogger::class),
        );
    }

    private function capaInStatus(string $status): CorrectiveAction
    {
        $action = new CorrectiveAction();
        $action->setStatus($status);
        $action->setTitle('Test CAPA');
        return $action;
    }
}
