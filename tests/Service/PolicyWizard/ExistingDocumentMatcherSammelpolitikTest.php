<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Service\PolicyWizard\ExistingDocumentMatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W4-C — MUST #3: Sammelpolitik (umbrella-policy) detection.
 *
 * Verifies that {@see ExistingDocumentMatcher} surfaces the synthetic
 * `MATCHER_SAMMELPOLITIK` flag when a document matches the top-level
 * policy keyword AND covers ≥3 distinct ISO 27002 topics. Naively
 * `replace`-ing such a document would silently drop the bulk of its
 * pages, so the Bestandsaufnahme step needs to default it to
 * `split_to_topics`.
 */
final class ExistingDocumentMatcherSammelpolitikTest extends TestCase
{
    private function makeDocument(
        string $title,
        ?string $description = null,
        ?int $fileSize = 1,
        ?string $category = 'policy',
    ): Document {
        $doc = new Document();
        $doc->setOriginalFilename($title);
        $doc->setFilename($title);
        if ($category !== null) {
            $doc->setCategory($category);
        }
        $doc->setMimeType('application/pdf');
        $doc->setFileSize($fileSize ?? 1);
        $doc->setFilePath('virtual:test/' . $title);
        $doc->setDescription($description);
        return $doc;
    }

    #[Test]
    public function sammelpolitikDetectedWhenTopLevelPlusMultiKeywords(): void
    {
        $matcher = new ExistingDocumentMatcher();
        // "Sicherheitsleitlinie" → top_level; description packs in 3 more
        // topic keywords (access control + crypto + backup + logging).
        $doc = $this->makeDocument(
            'IT-Sicherheitsleitlinie 2023',
            'Inkl. Zugriffskontrolle, Kryptographie, Backup und Logging.',
            fileSize: 10_000,
        );

        $hits = $matcher->match($doc);

        self::assertNotEmpty($hits);
        self::assertSame(
            ExistingDocumentMatcher::MATCHER_SAMMELPOLITIK,
            $hits[0]['topic'],
            'Sammelpolitik must be the #1 suggestion when top_level + ≥3 topics match.',
        );
        self::assertEqualsWithDelta(
            0.85,
            $hits[0]['confidence'],
            0.0001,
            'Base sammelpolitik confidence is 0.85 for small files.',
        );
    }

    #[Test]
    public function sammelpolitikBoostedWhenLargeFileSize(): void
    {
        $matcher = new ExistingDocumentMatcher();
        $doc = $this->makeDocument(
            'IT-Sicherheitsleitlinie 80 Seiten',
            'Zugriffskontrolle, Kryptographie, Backup, Logging, Patch.',
            fileSize: 250_000, // 250 KB → above 50 KB threshold.
        );

        $hits = $matcher->match($doc);

        self::assertSame(ExistingDocumentMatcher::MATCHER_SAMMELPOLITIK, $hits[0]['topic']);
        self::assertEqualsWithDelta(
            0.9,
            $hits[0]['confidence'],
            0.0001,
            'Large files (>50 KB) get the boosted 0.90 confidence.',
        );
    }

    #[Test]
    public function noSammelpolitikForCleanTopLevel(): void
    {
        $matcher = new ExistingDocumentMatcher();
        // "Sicherheitsleitlinie" alone → top_level only, no other topics.
        $doc = $this->makeDocument('Sicherheitsleitlinie');

        $hits = $matcher->match($doc);

        self::assertNotEmpty($hits);
        self::assertNotSame(
            ExistingDocumentMatcher::MATCHER_SAMMELPOLITIK,
            $hits[0]['topic'],
            'Single top_level hit must NOT trigger sammelpolitik.',
        );
        self::assertSame('top_level', $hits[0]['topic']);
    }

    #[Test]
    public function noSammelpolitikForLowKeywordCount(): void
    {
        $matcher = new ExistingDocumentMatcher();
        // top_level + only 1 other topic → < min hits threshold (3).
        $doc = $this->makeDocument(
            'Sicherheitsleitlinie und Backup-Konzept',
            null,
            fileSize: 200_000,
        );

        $hits = $matcher->match($doc);

        self::assertNotEmpty($hits);
        $topics = array_column($hits, 'topic');
        self::assertNotContains(
            ExistingDocumentMatcher::MATCHER_SAMMELPOLITIK,
            $topics,
            'Two topic hits (top_level + backup) is below the ≥3 sammelpolitik threshold.',
        );
    }
}
