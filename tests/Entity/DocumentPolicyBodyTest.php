<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Document;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Editable-policy-body extension on {@see Document}.
 *
 * Until W7-X the rendered policy body of a wizard-generated Document
 * lived ONLY in the translation file + substitutionVariables JSON
 * map; the PDF exporter re-rendered the body on every export and
 * tenants had no way to customise the body for tenant-specific
 * clauses.
 *
 * The tests here lock in the four-axis contract of the new columns:
 *   1. round-trip — getter/setter parity for policyBody +
 *      policyBodyEditedAt + policyBodyEditedBy
 *   2. hasPostGenerationEdits — true iff editedAt OR editedBy is set
 *   3. getEffectivePolicyBody — returns persisted body when set, else
 *      null (caller falls back to translation re-render)
 *   4. edit-tracking — the three columns can be cleared independently
 *      so a force-revert path is possible without touching the body
 */
final class DocumentPolicyBodyTest extends TestCase
{
    #[Test]
    public function testRoundTripGettersAndSetters(): void
    {
        $doc = new Document();
        $body = "# Heading\n\nA paragraph.";
        $editedAt = new DateTimeImmutable('2026-05-08 14:30:00');
        $user = new User();
        $user->setEmail('ciso@example.com');
        $user->setFirstName('Anna');
        $user->setLastName('Müller');

        $doc->setPolicyBody($body);
        $doc->setPolicyBodyEditedAt($editedAt);
        $doc->setPolicyBodyEditedBy($user);

        self::assertSame($body, $doc->getPolicyBody());
        self::assertSame($editedAt, $doc->getPolicyBodyEditedAt());
        self::assertSame($user, $doc->getPolicyBodyEditedBy());
    }

    #[Test]
    public function testHasPostGenerationEditsFalseOnFreshDocument(): void
    {
        $doc = new Document();
        $doc->setPolicyBody("# Wizard baseline\n\nUntouched.");

        // Setting only the body (the wizard-generation path) must NOT
        // be flagged as a manual edit — that signal is reserved for
        // tenant-driven post-generation customisations.
        self::assertFalse($doc->hasPostGenerationEdits());
    }

    #[Test]
    public function testHasPostGenerationEditsTrueOnTimestamp(): void
    {
        $doc = new Document();
        $doc->setPolicyBody("# Edited.");
        $doc->setPolicyBodyEditedAt(new DateTimeImmutable());

        self::assertTrue($doc->hasPostGenerationEdits());
    }

    #[Test]
    public function testHasPostGenerationEditsTrueOnUserOnly(): void
    {
        // Defensive: if the FK-SET-NULL race nulls the timestamp but
        // not the user (or vice versa), the drift signal still fires.
        $doc = new Document();
        $user = new User();
        $user->setEmail('ciso@example.com');
        $doc->setPolicyBodyEditedBy($user);

        self::assertTrue($doc->hasPostGenerationEdits());
    }

    #[Test]
    public function testGetEffectivePolicyBodyReturnsPersistedWhenSet(): void
    {
        $doc = new Document();
        $body = "# Tenant override\n\nThis is the customised body.";
        $doc->setPolicyBody($body);

        self::assertSame($body, $doc->getEffectivePolicyBody());
    }

    #[Test]
    public function testGetEffectivePolicyBodyReturnsNullWhenUnset(): void
    {
        // Legacy rows (pre-W7-X) have NULL policyBody — the exporter
        // must fall through to the translation re-render path.
        $doc = new Document();

        self::assertNull($doc->getEffectivePolicyBody());
    }

    #[Test]
    public function testEditTrackingClearsIndependently(): void
    {
        // Force-revert path: clear edit metadata without touching
        // the persisted body. The drift signal must drop after the
        // metadata is wiped.
        $doc = new Document();
        $doc->setPolicyBody("# Edited.");
        $doc->setPolicyBodyEditedAt(new DateTimeImmutable());
        $user = new User();
        $user->setEmail('ciso@example.com');
        $doc->setPolicyBodyEditedBy($user);

        self::assertTrue($doc->hasPostGenerationEdits());

        $doc->setPolicyBodyEditedAt(null);
        $doc->setPolicyBodyEditedBy(null);

        self::assertFalse($doc->hasPostGenerationEdits());
        self::assertSame("# Edited.", $doc->getPolicyBody());
    }
}
