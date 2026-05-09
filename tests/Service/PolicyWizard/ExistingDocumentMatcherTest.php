<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Service\PolicyWizard\ExistingDocumentMatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W4-C — ExistingDocumentMatcher tests.
 *
 * Pure-function matcher; tests cover keyword hits across DE/EN, the
 * documentType-based fallback path, the multi-word > single-word
 * confidence ordering, and the descending-by-confidence sort.
 */
final class ExistingDocumentMatcherTest extends TestCase
{
    private function makeDocument(string $title, ?string $category = 'policy', ?string $description = null): Document
    {
        $doc = new Document();
        $doc->setOriginalFilename($title);
        $doc->setFilename($title);
        if ($category !== null) {
            $doc->setCategory($category);
        }
        $doc->setMimeType('text/markdown');
        $doc->setFileSize(1);
        $doc->setFilePath('virtual:test/' . $title);
        $doc->setDescription($description);
        return $doc;
    }

    #[Test]
    public function titleBasedKeywordMatchProducesHighConfidence(): void
    {
        $matcher = new ExistingDocumentMatcher();
        $hits = $matcher->match($this->makeDocument('Access Control Policy 2024'));

        self::assertNotEmpty($hits);
        self::assertSame('access_control', $hits[0]['topic']);
        self::assertGreaterThanOrEqual(0.9, $hits[0]['confidence'], 'Multi-word keyword "access control" → ≥0.9 confidence.');
    }

    #[Test]
    public function germanKeywordMatchesProduceHits(): void
    {
        $matcher = new ExistingDocumentMatcher();
        $hits = $matcher->match($this->makeDocument('Zugriffskontrolle 2023'));

        self::assertNotEmpty($hits);
        $topics = array_column($hits, 'topic');
        self::assertContains('access_control', $topics, 'German "Zugriffskontrolle" must map to access_control.');
    }

    #[Test]
    public function fallbackToDocumentTypeWhenNoKeywordHit(): void
    {
        $matcher = new ExistingDocumentMatcher();
        $hits = $matcher->match($this->makeDocument('Some Random Title XYZ', 'plan'));

        self::assertNotEmpty($hits, 'Fallback to category-based topic must produce a hit.');
        self::assertSame('continuity', $hits[0]['topic']);
        self::assertSame(0.4, $hits[0]['confidence']);
    }

    #[Test]
    public function fallbackToTopLevelForPolicyCategory(): void
    {
        $matcher = new ExistingDocumentMatcher();
        $hits = $matcher->match($this->makeDocument('Random Policy', 'policy'));

        self::assertNotEmpty($hits);
        self::assertSame('top_level', $hits[0]['topic']);
    }

    #[Test]
    public function multipleKeywordsProduceSortedSuggestions(): void
    {
        $matcher = new ExistingDocumentMatcher();
        // Title contains "Access Control" (high confidence) AND "Backup" (high too).
        $hits = $matcher->match($this->makeDocument('Access Control and Backup Policy'));

        self::assertGreaterThanOrEqual(2, count($hits));
        // Confidences should be sorted descending.
        $previous = 1.1;
        foreach ($hits as $hit) {
            self::assertLessThanOrEqual($previous, $hit['confidence']);
            $previous = $hit['confidence'];
        }
        $topics = array_column($hits, 'topic');
        self::assertContains('access_control', $topics);
        self::assertContains('backup', $topics);
    }

    #[Test]
    public function knownTopicsListIsNonEmptyAndIncludesTopLevel(): void
    {
        $topics = ExistingDocumentMatcher::knownTopics();
        self::assertNotEmpty($topics);
        self::assertContains('top_level', $topics);
        self::assertContains('access_control', $topics);
    }
}
