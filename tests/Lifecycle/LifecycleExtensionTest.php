<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Lifecycle\LifecycleRegistry;
use App\Twig\LifecycleExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Twig glue around LifecycleRegistry (audit-s3 P-4).
 *
 * Pins the semantic-tone → Aurora-variant mapping: the registry's
 * `info` reads as Aurora `primary`, `muted` reads as `neutral`. This
 * keeps the registry semantically rich without leaking design tokens.
 */
final class LifecycleExtensionTest extends TestCase
{
    private LifecycleExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new LifecycleExtension(new LifecycleRegistry());
    }

    #[Test]
    public function testToneMapsSemanticValuesToAuroraVariants(): void
    {
        // draft is registered as neutral → Aurora neutral
        self::assertSame('neutral', $this->extension->lifecycleTone(StandardEntityFixture::class, 'draft'));
        // in_review is registered as info → Aurora primary
        self::assertSame('primary', $this->extension->lifecycleTone(StandardEntityFixture::class, 'in_review'));
        // approved is registered as success → Aurora success
        self::assertSame('success', $this->extension->lifecycleTone(StandardEntityFixture::class, 'approved'));
        // published is registered as primary → Aurora primary
        self::assertSame('primary', $this->extension->lifecycleTone(StandardEntityFixture::class, 'published'));
        // archived is registered as muted → Aurora neutral
        self::assertSame('neutral', $this->extension->lifecycleTone(StandardEntityFixture::class, 'archived'));
    }

    #[Test]
    public function testToneForUnknownStatusFallsBackToNeutral(): void
    {
        self::assertSame('neutral', $this->extension->lifecycleTone(StandardEntityFixture::class, 'bogus'));
    }

    #[Test]
    public function testTransitionsForwardsToRegistry(): void
    {
        self::assertSame(
            ['in_review'],
            $this->extension->lifecycleTransitions(StandardEntityFixture::class, 'draft'),
        );
        self::assertSame(
            ['approved', 'draft'],
            $this->extension->lifecycleTransitions(StandardEntityFixture::class, 'in_review'),
        );
    }

    #[Test]
    public function testStagesReturnsRegisteredOrder(): void
    {
        self::assertSame(
            ['draft', 'in_review', 'approved', 'published', 'archived'],
            $this->extension->lifecycleStages(StandardEntityFixture::class),
        );
        self::assertSame(
            ['open', 'in_progress', 'resolved', 'closed'],
            $this->extension->lifecycleStages(FindingEntityFixture::class),
        );
    }
}
