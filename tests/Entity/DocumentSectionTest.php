<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\DocumentSection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the DocumentSection entity (Phase 4-C / W3-C).
 *
 * Verifies the per-section state machine constants, the privacy-section
 * key-prefix detection used by DocumentSectionVoter, and the
 * setStatus() input validation guarantee.
 */
class DocumentSectionTest extends TestCase
{
    #[Test]
    public function defaultStatusIsDraft(): void
    {
        $section = new DocumentSection();
        self::assertSame(DocumentSection::STATUS_DRAFT, $section->getStatus());
        self::assertFalse($section->isApproved());
        self::assertFalse($section->isRejected());
        self::assertNotNull($section->getCreatedAt());
    }

    #[Test]
    public function isPrivacySectionDetectsPrefix(): void
    {
        $section = new DocumentSection();
        $section->setSectionKey('privacy_addendum');
        self::assertTrue($section->isPrivacySection());

        $section->setSectionKey('privacy_addendum_breach');
        self::assertTrue($section->isPrivacySection());

        $section->setSectionKey('access_control_appendix');
        self::assertFalse($section->isPrivacySection());
    }

    #[Test]
    public function setStatusRejectsUnknownValue(): void
    {
        $section = new DocumentSection();
        $this->expectException(\App\Exception\InvalidArgument\InvalidArgumentException::class);
        // ROLE_DPO must NOT be allowed to push the section into a state
        // outside the allow-list — section state is regulator-relevant.
        $section->setStatus('foo_bar_unknown');
    }
}
