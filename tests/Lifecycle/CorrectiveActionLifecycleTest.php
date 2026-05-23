<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Lifecycle\LifecycleRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Structural assertions on the CAPA_STAGES constant exposed by
 * {@see LifecycleRegistry}. Behaviour-level evidence-required guard is
 * enforced via Form Callback validator on CorrectiveActionType (see
 * CorrectiveActionTypeTest) — this suite only pins the lifecycle shape.
 *
 * Audit-Trail: S3 P0-30/32 — Cl. 10.1 b.
 *
 * Junior-ISB-Audit-2026-05-22 CAPA-Lifecycle: stages extended from 6 → 7
 * with the new intermediate `verified` place (ISO 27001 Cl. 10.1 d —
 * forced `completed → verify` step before the verdict).
 */
final class CorrectiveActionLifecycleTest extends TestCase
{
    #[Test]
    public function capaStagesContainsTheSevenCanonicalKeys(): void
    {
        $this->assertSame(
            ['planned', 'in_progress', 'completed', 'verified', 'verified_effective', 'verified_ineffective', 'cancelled'],
            array_keys(LifecycleRegistry::CAPA_STAGES),
        );
    }

    #[Test]
    public function plannedAllowsInProgressOrCancelled(): void
    {
        $this->assertSame(
            ['in_progress', 'cancelled'],
            LifecycleRegistry::CAPA_STAGES['planned']['transitions'],
        );
    }

    #[Test]
    public function completedForcesVerificationOpening(): void
    {
        // Junior-ISB-Audit-2026-05-22 CAPA-Lifecycle: `completed` no longer
        // jumps straight to a verdict — the verifier MUST open verification
        // (ISO 27001 Cl. 10.1 d).
        $this->assertSame(
            ['verified'],
            LifecycleRegistry::CAPA_STAGES['completed']['transitions'],
        );
    }

    #[Test]
    public function verifiedAllowsEffectiveOrIneffectiveVerdict(): void
    {
        $this->assertSame(
            ['verified_effective', 'verified_ineffective'],
            LifecycleRegistry::CAPA_STAGES['verified']['transitions'],
        );
    }

    #[Test]
    public function verifiedStatusesAreTerminal(): void
    {
        $this->assertSame([], LifecycleRegistry::CAPA_STAGES['verified_effective']['transitions']);
        $this->assertSame([], LifecycleRegistry::CAPA_STAGES['verified_ineffective']['transitions']);
        $this->assertSame([], LifecycleRegistry::CAPA_STAGES['cancelled']['transitions']);
    }

    #[Test]
    public function ineffectiveStageHasDangerTone(): void
    {
        $this->assertSame('danger', LifecycleRegistry::CAPA_STAGES['verified_ineffective']['tone']);
    }
}
