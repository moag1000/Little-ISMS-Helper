<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Document;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Compliance-Manager / Auditor-External Wish — verifies the
 * tag-derived helpers on {@see Document} correctly parse the
 * `dora-validity:YYYY-MM-DD` and `climate-change:amended` markers
 * that the {@see \App\Service\PolicyWizard\DocumentGenerator}
 * emits via EntityTag rows.
 *
 * The helpers are pure functions (no DB access, no side-effects);
 * controllers + the PDF exporter are responsible for resolving the
 * active tag-name list via {@see \App\Repository\EntityTagRepository}
 * and feeding the names into the parsers.
 */
final class DocumentDoraValidityTest extends TestCase
{
    #[Test]
    public function testParsesValidDoraValidityTag(): void
    {
        $tags = ['policy-wizard-generated', 'standard:dora', 'dora-validity:2025-01-17'];
        $parsed = Document::parseDoraValidityFromTags($tags);
        self::assertInstanceOf(DateTimeImmutable::class, $parsed);
        self::assertSame('2025-01-17', $parsed->format('Y-m-d'));
    }

    #[Test]
    public function testReturnsNullWhenTagAbsent(): void
    {
        $tags = ['policy-wizard-generated', 'standard:iso27001', 'topic:top_level'];
        self::assertNull(Document::parseDoraValidityFromTags($tags));
    }

    #[Test]
    public function testReturnsNullWhenTagListEmpty(): void
    {
        self::assertNull(Document::parseDoraValidityFromTags([]));
    }

    #[Test]
    public function testRejectsMalformedDoraValidityPayload(): void
    {
        // Wrong format — must be YYYY-MM-DD.
        self::assertNull(Document::parseDoraValidityFromTags(['dora-validity:not-a-date']));
        self::assertNull(Document::parseDoraValidityFromTags(['dora-validity:17.01.2025']));
        self::assertNull(Document::parseDoraValidityFromTags(['dora-validity:']));
        // Implausible-but-syntactically-valid payload (Feb 30) is
        // rejected by strict createFromFormat.
        self::assertNull(Document::parseDoraValidityFromTags(['dora-validity:2025-02-30']));
    }

    #[Test]
    public function testIgnoresNonStringEntries(): void
    {
        // iterable<int, string> contract — runtime feed may include
        // junk; parser must skip rather than choke.
        $mixed = [42, null, false, 'dora-validity:2026-12-31'];
        // The static contract is iterable<int, string>; we cast each
        // entry inside the parser. Simulate with an explicit typed
        // array in real callers; here we rely on the loop's is_string
        // guard inside the parser.
        /** @var iterable<int, string> $iter */
        $iter = (function () use ($mixed): \Generator {
            foreach ($mixed as $entry) {
                /** @phpstan-ignore-next-line — intentional bad-input */
                yield $entry;
            }
        })();
        $parsed = Document::parseDoraValidityFromTags($iter);
        self::assertInstanceOf(DateTimeImmutable::class, $parsed);
        self::assertSame('2026-12-31', $parsed->format('Y-m-d'));
    }

    #[Test]
    public function testReturnsFirstMatchingTag(): void
    {
        // Multiple validity tags shouldn't normally coexist but the
        // parser must be deterministic — first wins.
        $tags = [
            'dora-validity:2025-01-17',
            'dora-validity:2026-06-30',
        ];
        $parsed = Document::parseDoraValidityFromTags($tags);
        self::assertSame('2025-01-17', $parsed?->format('Y-m-d'));
    }

    #[Test]
    public function testClimateChangeAwareDetection(): void
    {
        self::assertTrue(Document::isClimateChangeAwareFromTags([
            'policy-wizard-generated',
            'standard:iso27001',
            'climate-change:amended',
        ]));
        self::assertFalse(Document::isClimateChangeAwareFromTags([
            'policy-wizard-generated',
            'standard:iso27001',
        ]));
        self::assertFalse(Document::isClimateChangeAwareFromTags([]));
        // Partial-prefix match must not trip the flag — exact name only.
        self::assertFalse(Document::isClimateChangeAwareFromTags([
            'climate-change:partial',
            'climate-change-amended',
        ]));
    }
}
