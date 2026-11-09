<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\CorrectiveAction;
use App\Lifecycle\LifecycleRegistry;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Structural assertions on the CAPA lifecycle as {@see LifecycleRegistry} reads it
 * from `corrective_action_lifecycle` (W7: from the workflow, no constant any more). Behaviour-level evidence-required guard is
 * enforced via Form Callback validator on CorrectiveActionType (see
 * CorrectiveActionTypeTest) — this suite only pins the lifecycle shape.
 *
 * Audit-Trail: S3 P0-30/32 — Cl. 10.1 b.
 *
 * Junior-ISB-Audit-2026-05-22 CAPA-Lifecycle: stages extended from 6 → 7
 * with the new intermediate `verified` place (ISO 27001 Cl. 10.1 d —
 * forced `completed → verify` step before the verdict).
 */
final class CorrectiveActionLifecycleTest extends KernelTestCase
{
    /** @return array<string, array{transitions: list<string>, tone: string}> */
    private function stages(): array
    {
        self::bootKernel();

        return self::getContainer()->get(LifecycleRegistry::class)->getLifecycle(CorrectiveAction::class);
    }

    #[Test]
    public function capaStagesContainsTheSevenCanonicalKeys(): void
    {
        $this->assertSame(
            ['planned', 'in_progress', 'completed', 'verified', 'verified_effective', 'verified_ineffective', 'cancelled'],
            array_keys($this->stages()),
        );
    }

    #[Test]
    public function plannedAllowsInProgressOrCancelled(): void
    {
        $this->assertSame(['in_progress', 'cancelled'], $this->stages()['planned']['transitions']);
    }

    #[Test]
    public function completedForcesVerificationOpening(): void
    {
        // Junior-ISB-Audit-2026-05-22 CAPA-Lifecycle: `completed` no longer
        // jumps straight to a verdict — the verifier MUST open verification
        // (ISO 27001 Cl. 10.1 d).
        $this->assertSame(['verified'], $this->stages()['completed']['transitions']);
    }

    #[Test]
    public function verifiedAllowsEffectiveOrIneffectiveVerdict(): void
    {
        $this->assertSame(
            ['verified_effective', 'verified_ineffective'],
            $this->stages()['verified']['transitions'],
        );
    }

    #[Test]
    public function effectiveAndCancelledAreTerminalIneffectiveReopens(): void
    {
        $stages = $this->stages();
        $this->assertSame([], $stages['verified_effective']['transitions']);
        $this->assertSame([], $stages['cancelled']['transitions']);
        // An ineffective action goes back into work (ISO 27001 Cl. 10.1 e).
        $this->assertSame(['in_progress'], $stages['verified_ineffective']['transitions']);
    }

    #[Test]
    public function ineffectiveStageHasDangerTone(): void
    {
        $this->assertSame('danger', $this->stages()['verified_ineffective']['tone']);
    }
}
