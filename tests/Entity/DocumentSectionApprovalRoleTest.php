<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\DocumentSection;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the W6-A DPO-Veto entity extensions on DocumentSection.
 *
 * Covers:
 *  - approvalRole enum boundary (only `ciso|dpo|joint` accepted; null OK
 *    so legacy rows without the column don't blow up before backfill).
 *  - getter/setter contract for approvalRole + editLocked + authoredByUser.
 *  - editLocked default + persistence-shape (non-nullable boolean, false
 *    by default — matches the migration's `DEFAULT 0` clause).
 *
 * No kernel boot — pure entity-level smoke tests so they run in <1s.
 */
class DocumentSectionApprovalRoleTest extends TestCase
{
    #[Test]
    public function approvalRoleAcceptsOnlyAllowedValuesAndNull(): void
    {
        $section = new DocumentSection();
        // Default: NULL (legacy rows; backfilled via migration to `ciso`).
        self::assertNull($section->getApprovalRole());

        // Each enum constant must round-trip cleanly.
        $section->setApprovalRole(DocumentSection::APPROVAL_ROLE_CISO);
        self::assertSame('ciso', $section->getApprovalRole());

        $section->setApprovalRole(DocumentSection::APPROVAL_ROLE_DPO);
        self::assertSame('dpo', $section->getApprovalRole());

        $section->setApprovalRole(DocumentSection::APPROVAL_ROLE_JOINT);
        self::assertSame('joint', $section->getApprovalRole());

        // Null reset is allowed (e.g. when un-gating a section).
        $section->setApprovalRole(null);
        self::assertNull($section->getApprovalRole());

        // Unknown values throw — section-state must stay regulator-clean.
        $this->expectException(\App\Exception\InvalidArgument\InvalidArgumentException::class);
        $section->setApprovalRole('owner');
    }

    #[Test]
    public function authoredByUserGetterAndSetterRoundTrip(): void
    {
        $section = new DocumentSection();
        self::assertNull($section->getAuthoredByUser());

        // Use createStub() — the test only reads getId(), no method-call
        // expectations are set, so a stub is the correct choice and
        // sidesteps the PHPUnit "no expectations on mock" notice.
        $author = $this->createStub(User::class);
        $author->method('getId')->willReturn(42);

        $section->setAuthoredByUser($author);
        self::assertSame($author, $section->getAuthoredByUser());
        self::assertSame(42, $section->getAuthoredByUser()?->getId());

        // Re-set to null when content is re-authored anonymously
        // (e.g. system-generated re-renders).
        $section->setAuthoredByUser(null);
        self::assertNull($section->getAuthoredByUser());
    }

    #[Test]
    public function editLockedDefaultsToFalseAndPersists(): void
    {
        $section = new DocumentSection();
        self::assertFalse($section->isEditLocked(), 'fresh sections must default to unlocked');

        // Lock → persists.
        $section->setEditLocked(true);
        self::assertTrue($section->isEditLocked());

        // Unlock (DPO veto / re-edit path) → persists.
        $section->setEditLocked(false);
        self::assertFalse($section->isEditLocked());
    }
}
