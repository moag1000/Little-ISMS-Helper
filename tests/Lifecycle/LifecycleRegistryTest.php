<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Lifecycle\Lifecycle;
use App\Lifecycle\LifecycleRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pin-test for the LifecycleRegistry (audit-s3 P-4).
 *
 * Guards CLAUDE.md's canonical 5-stage transition matrix
 * (draft → in_review → approved → published → archived plus the
 * documented side-paths). If a future change drifts the matrix, this
 * test fails first and forces an explicit decision.
 */
final class LifecycleRegistryTest extends TestCase
{
    private LifecycleRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new LifecycleRegistry();
    }

    #[Test]
    public function testStandard5StageMatrixMatchesClaudeMdSpec(): void
    {
        $expected = [
            'draft' => ['in_review'],
            'in_review' => ['approved', 'draft'],
            'approved' => ['published'],
            'published' => ['archived'],
            'archived' => ['published'],
        ];

        foreach ($expected as $from => $allowed) {
            self::assertSame(
                $allowed,
                $this->registry->getAllowedTransitions(StandardEntityFixture::class, $from),
                sprintf('Transitions for status "%s" must match CLAUDE.md', $from),
            );
        }
    }

    #[Test]
    public function testEntityWithoutLifecycleAttributeFallsBackToStandard5Stage(): void
    {
        self::assertSame(
            LifecycleRegistry::STANDARD_5_STAGE,
            $this->registry->getLifecycle(StandardEntityFixture::class),
        );
        self::assertSame(
            ['draft', 'in_review', 'approved', 'published', 'archived'],
            $this->registry->getStages(StandardEntityFixture::class),
        );
    }

    #[Test]
    public function testEntityWithLifecycleAttributeUsesOverride(): void
    {
        self::assertSame(
            LifecycleRegistry::FINDING_4_STAGE,
            $this->registry->getLifecycle(FindingEntityFixture::class),
        );
        self::assertSame(
            ['open', 'in_progress', 'resolved', 'closed'],
            $this->registry->getStages(FindingEntityFixture::class),
        );
    }

    #[Test]
    public function testIsValidTransitionEnforcesStandardMatrix(): void
    {
        // Allowed transitions
        self::assertTrue($this->registry->isValidTransition(StandardEntityFixture::class, 'draft', 'in_review'));
        self::assertTrue($this->registry->isValidTransition(StandardEntityFixture::class, 'in_review', 'approved'));
        self::assertTrue($this->registry->isValidTransition(StandardEntityFixture::class, 'in_review', 'draft'));
        self::assertTrue($this->registry->isValidTransition(StandardEntityFixture::class, 'approved', 'published'));
        self::assertTrue($this->registry->isValidTransition(StandardEntityFixture::class, 'published', 'archived'));
        self::assertTrue($this->registry->isValidTransition(StandardEntityFixture::class, 'archived', 'published'));

        // Disallowed transitions
        self::assertFalse($this->registry->isValidTransition(StandardEntityFixture::class, 'draft', 'approved'));
        self::assertFalse($this->registry->isValidTransition(StandardEntityFixture::class, 'draft', 'published'));
        self::assertFalse($this->registry->isValidTransition(StandardEntityFixture::class, 'approved', 'draft'));
        self::assertFalse($this->registry->isValidTransition(StandardEntityFixture::class, 'published', 'draft'));
        self::assertFalse($this->registry->isValidTransition(StandardEntityFixture::class, 'archived', 'draft'));
        self::assertFalse($this->registry->isValidTransition(StandardEntityFixture::class, 'draft', 'archived'));
    }

    #[Test]
    public function testFindingLifecycleOverridesAreDistinctFromStandard(): void
    {
        // 4-stage discovery lifecycle is structurally different
        self::assertTrue($this->registry->isValidTransition(FindingEntityFixture::class, 'open', 'in_progress'));
        self::assertTrue($this->registry->isValidTransition(FindingEntityFixture::class, 'in_progress', 'resolved'));
        self::assertTrue($this->registry->isValidTransition(FindingEntityFixture::class, 'resolved', 'closed'));
        self::assertTrue($this->registry->isValidTransition(FindingEntityFixture::class, 'resolved', 'in_progress'));
        // 'closed' is terminal
        self::assertSame([], $this->registry->getAllowedTransitions(FindingEntityFixture::class, 'closed'));
        // 'draft' is not part of the finding lifecycle at all
        self::assertSame([], $this->registry->getAllowedTransitions(FindingEntityFixture::class, 'draft'));
    }

    #[Test]
    public function testUnknownStatusReturnsEmptyTransitions(): void
    {
        self::assertSame(
            [],
            $this->registry->getAllowedTransitions(StandardEntityFixture::class, 'bogus_status'),
        );
        self::assertFalse(
            $this->registry->isValidTransition(StandardEntityFixture::class, 'bogus', 'draft'),
        );
    }

    #[Test]
    public function testUnknownEntityClassFallsBackToStandard(): void
    {
        // Non-existent class must not blow up — fall back to STANDARD_5_STAGE
        self::assertSame(
            LifecycleRegistry::STANDARD_5_STAGE,
            $this->registry->getLifecycle('Bogus\\\\NotARealClass'),
        );
    }

    #[Test]
    public function testGetToneReturnsExpectedTonesForStandardLifecycle(): void
    {
        self::assertSame('neutral', $this->registry->getTone(StandardEntityFixture::class, 'draft'));
        self::assertSame('info', $this->registry->getTone(StandardEntityFixture::class, 'in_review'));
        self::assertSame('success', $this->registry->getTone(StandardEntityFixture::class, 'approved'));
        self::assertSame('primary', $this->registry->getTone(StandardEntityFixture::class, 'published'));
        self::assertSame('muted', $this->registry->getTone(StandardEntityFixture::class, 'archived'));
    }

    #[Test]
    public function testHasStatusValidatesEntityLifecycleMembership(): void
    {
        self::assertTrue($this->registry->hasStatus(StandardEntityFixture::class, 'draft'));
        self::assertFalse($this->registry->hasStatus(StandardEntityFixture::class, 'open'));
        self::assertTrue($this->registry->hasStatus(FindingEntityFixture::class, 'open'));
        self::assertFalse($this->registry->hasStatus(FindingEntityFixture::class, 'draft'));
    }
}

/**
 * Fixture: entity without #[Lifecycle] attribute — uses STANDARD_5_STAGE.
 *
 * @internal
 */
class StandardEntityFixture
{
    public function __construct(public string $status = 'draft') {}
}

/**
 * Fixture: entity with #[Lifecycle] attribute pinned to FINDING_4_STAGE.
 *
 * @internal
 */
#[Lifecycle(stages: LifecycleRegistry::FINDING_4_STAGE)]
class FindingEntityFixture
{
    public function __construct(public string $status = 'open') {}
}
