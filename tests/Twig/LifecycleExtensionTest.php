<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Entity\Document;
use App\Lifecycle\LifecycleRegistry;
use App\Twig\LifecycleExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Unit tests for LifecycleExtension (lifecycle X.3).
 *
 * Covers:
 *  - lifecycle_tone returns Aurora variant for known status.
 *  - lifecycle_can delegates to Security::isGranted with correct attribute string.
 *  - lifecycle_can returns false when Security denies.
 */
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class LifecycleExtensionTest extends TestCase
{
    private function makeExtension(Security $security): LifecycleExtension
    {
        return new LifecycleExtension(new LifecycleRegistry(), $security);
    }

    #[Test]
    public function lifecycleToneReturnsPrimaryForPublished(): void
    {
        $security = $this->createMock(Security::class);
        $ext = $this->makeExtension($security);

        $this->assertSame('primary', $ext->lifecycleTone(Document::class, 'published'));
    }

    #[Test]
    public function lifecycleToneReturnsNeutralForUnknown(): void
    {
        $security = $this->createMock(Security::class);
        $ext = $this->makeExtension($security);

        $this->assertSame('neutral', $ext->lifecycleTone(Document::class, 'unknown_status_xyz'));
    }

    #[Test]
    public function lifecycleCanReturnsTrueWhenGranted(): void
    {
        $security = $this->createMock(Security::class);
        $entity   = new Document();

        $security->method('isGranted')
            ->with('lifecycle.document_lifecycle.submit_for_review', $entity)
            ->willReturn(true);

        $ext = $this->makeExtension($security);
        $this->assertTrue($ext->lifecycleCan($entity, 'document_lifecycle', 'submit_for_review'));
    }

    #[Test]
    public function lifecycleCanReturnsFalseWhenDenied(): void
    {
        $security = $this->createMock(Security::class);
        $entity   = new Document();

        $security->method('isGranted')
            ->willReturn(false);

        $ext = $this->makeExtension($security);
        $this->assertFalse($ext->lifecycleCan($entity, 'document_lifecycle', 'approve'));
    }
}
