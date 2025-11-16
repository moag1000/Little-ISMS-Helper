<?php

namespace App\Tests\Service;

use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Service\AutomatedGapAnalysisService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AutomatedGapAnalysisServiceTest extends TestCase
{
    private AutomatedGapAnalysisService $service;

    protected function setUp(): void
    {
        $this->service = new AutomatedGapAnalysisService();
    }

    public function testAnalyzeGapsWithNoGaps(): void
    {
        $mapping = $this->createMapping();
        $analysisResults = [
            'extracted_keywords' => [
                'source' => ['encryption', 'authentication', 'access control'],
                'target' => ['encryption', 'authentication', 'access control'],
            ],
            'textual_similarity' => 0.95,
            'structural_similarity' => 0.90,
        ];

        $gaps = $this->service->analyzeGaps($mapping, $analysisResults);

        // High similarity, same keywords = no gaps
        $this->assertEmpty($gaps);
    }

    public function testAnalyzeGapsWithMissingCriticalKeywords(): void
    {
        $mapping = $this->createMapping();
        $analysisResults = [
            'extracted_keywords' => [
                'source' => ['access control'],
                'target' => ['encryption', 'authentication', 'access control'],
            ],
            'textual_similarity' => 0.8,
            'structural_similarity' => 0.7,
        ];

        $gaps = $this->service->analyzeGaps($mapping, $analysisResults);

        // Should find missing keywords gap
        $this->assertNotEmpty($gaps);

        $keywordGap = $this->findGapByType($gaps, 'missing_control');
        $this->assertNotNull($keywordGap);
        $this->assertSame('critical', $keywordGap->getPriority());
        $this->assertContains('encryption', $keywordGap->getMissingKeywords());
        $this->assertContains('authentication', $keywordGap->getMissingKeywords());
    }

    public function testAnalyzeGapsWithHighPriorityMissingKeywords(): void
    {
        $mapping = $this->createMapping();
        $analysisResults = [
            'extracted_keywords' => [
                'source' => ['documentation'],
                'target' => ['documentation', 'monitoring', 'backup'],
            ],
            'textual_similarity' => 0.8,
            'structural_similarity' => 0.7,
        ];

        $gaps = $this->service->analyzeGaps($mapping, $analysisResults);

        $keywordGap = $this->findGapByType($gaps, 'missing_control');
        $this->assertNotNull($keywordGap);
        $this->assertSame('high', $keywordGap->getPriority());
    }

    public function testAnalyzeGapsWithManyMediumKeywords(): void
    {
        $mapping = $this->createMapping();
        $analysisResults = [
            'extracted_keywords' => [
                'source' => ['policy'],
                'target' => ['policy', 'review', 'approval', 'testing', 'validation', 'reporting', 'compliance'],
            ],
            'textual_similarity' => 0.8,
            'structural_similarity' => 0.7,
        ];

        $gaps = $this->service->analyzeGaps($mapping, $analysisResults);

        $keywordGap = $this->findGapByType($gaps, 'missing_control');
        $this->assertNotNull($keywordGap);
        // More than 5 missing keywords = high priority
        $this->assertSame('high', $keywordGap->getPriority());
    }

    public function testAnalyzeGapsWithPartialCoverage(): void
    {
        $mapping = $this->createMapping();
        $analysisResults = [
            'extracted_keywords' => [
                'source' => ['encryption'],
                'target' => ['encryption'],
            ],
            'textual_similarity' => 0.5, // Between 0.3 and 0.7
            'structural_similarity' => 0.7,
        ];

        $gaps = $this->service->analyzeGaps($mapping, $analysisResults);

        $partialGap = $this->findGapByType($gaps, 'partial_coverage');
        $this->assertNotNull($partialGap);
        $this->assertSame('identified', $partialGap->getStatus());
    }

    public function testAnalyzeGapsWithScopeDifferences(): void
    {
        $mapping = $this->createMapping();
        $analysisResults = [
            'extracted_keywords' => [
                'source' => ['encryption'],
                'target' => ['encryption'],
            ],
            'textual_similarity' => 0.8,
            'structural_similarity' => 0.3, // Below 0.5
        ];

        $gaps = $this->service->analyzeGaps($mapping, $analysisResults);

        $scopeGap = $this->findGapByType($gaps, 'scope_difference');
        $this->assertNotNull($scopeGap);
    }

    public function testAnalyzeGapsWithMultipleIssues(): void
    {
        $mapping = $this->createMapping();
        $analysisResults = [
            'extracted_keywords' => [
                'source' => ['policy'],
                'target' => ['encryption', 'policy', 'audit'],
            ],
            'textual_similarity' => 0.45, // Partial coverage
            'structural_similarity' => 0.35, // Scope difference
        ];

        $gaps = $this->service->analyzeGaps($mapping, $analysisResults);

        // Should have multiple gap types
        $this->assertGreaterThanOrEqual(3, count($gaps));

        $this->assertNotNull($this->findGapByType($gaps, 'missing_control'));
        $this->assertNotNull($this->findGapByType($gaps, 'partial_coverage'));
        $this->assertNotNull($this->findGapByType($gaps, 'scope_difference'));
    }

    public function testGapItemProperties(): void
    {
        $mapping = $this->createMapping();
        $analysisResults = [
            'extracted_keywords' => [
                'source' => ['policy'],
                'target' => ['encryption', 'policy'],
            ],
            'textual_similarity' => 0.8,
            'structural_similarity' => 0.7,
        ];

        $gaps = $this->service->analyzeGaps($mapping, $analysisResults);

        $this->assertNotEmpty($gaps);
        $gap = $gaps[0];

        // Check all required properties are set
        $this->assertSame($mapping, $gap->getMapping());
        $this->assertNotEmpty($gap->getGapType());
        $this->assertNotEmpty($gap->getPriority());
        $this->assertNotEmpty($gap->getDescription());
        $this->assertIsInt($gap->getPercentageImpact());
        $this->assertIsInt($gap->getConfidence());
        $this->assertSame('algorithm', $gap->getIdentificationSource());
        $this->assertSame('identified', $gap->getStatus());
    }

    public function testMissingKeywordsEstimatedEffort(): void
    {
        $mapping = $this->createMapping();
        $analysisResults = [
            'extracted_keywords' => [
                'source' => [],
                'target' => ['encryption', 'authentication', 'monitoring', 'review'],
            ],
            'textual_similarity' => 0.8,
            'structural_similarity' => 0.7,
        ];

        $gaps = $this->service->analyzeGaps($mapping, $analysisResults);

        $keywordGap = $this->findGapByType($gaps, 'missing_control');
        $this->assertNotNull($keywordGap);

        // 2 critical (encryption, authentication) * 2h = 4h
        // 1 high (monitoring) * 1h = 1h
        // 1 medium (review) * 0.5h = 0.5h
        // Total: 5.5h, ceil = 6h
        $this->assertGreaterThanOrEqual(4, $keywordGap->getEstimatedEffort());
    }

    public function testRecommendationsAreGenerated(): void
    {
        $mapping = $this->createMapping();
        $analysisResults = [
            'extracted_keywords' => [
                'source' => ['policy'],
                'target' => ['encryption', 'policy'],
            ],
            'textual_similarity' => 0.8,
            'structural_similarity' => 0.7,
        ];

        $gaps = $this->service->analyzeGaps($mapping, $analysisResults);

        $keywordGap = $this->findGapByType($gaps, 'missing_control');
        $this->assertNotNull($keywordGap);
        $this->assertNotEmpty($keywordGap->getRecommendedAction());
    }

    public function testAnalyzeGapsWithEmptyResults(): void
    {
        $mapping = $this->createMapping();
        $analysisResults = [];

        $gaps = $this->service->analyzeGaps($mapping, $analysisResults);

        // Should handle empty results gracefully
        $this->assertIsArray($gaps);
    }

    public function testAnalyzeGapsWithNullKeywords(): void
    {
        $mapping = $this->createMapping();
        $analysisResults = [
            'extracted_keywords' => [
                'source' => null,
                'target' => null,
            ],
            'textual_similarity' => 0.8,
            'structural_similarity' => 0.7,
        ];

        // Should handle null gracefully without errors
        $gaps = $this->service->analyzeGaps($mapping, $analysisResults);
        $this->assertIsArray($gaps);
    }

    public function testConfidenceCalculation(): void
    {
        $mapping = $this->createMapping();
        $analysisResults = [
            'extracted_keywords' => [
                'source' => ['policy'],
                'target' => ['encryption', 'authentication', 'policy'],
            ],
            'textual_similarity' => 0.8,
            'structural_similarity' => 0.7,
        ];

        $gaps = $this->service->analyzeGaps($mapping, $analysisResults);

        $keywordGap = $this->findGapByType($gaps, 'missing_control');
        $this->assertNotNull($keywordGap);

        // Confidence should be between 0 and 100
        $confidence = $keywordGap->getConfidence();
        $this->assertGreaterThanOrEqual(0, $confidence);
        $this->assertLessThanOrEqual(100, $confidence);
    }

    private function createMapping(): MockObject
    {
        $sourceRequirement = $this->createMock(ComplianceRequirement::class);
        $sourceRequirement->method('getCategory')->willReturn('Technical');
        $sourceRequirement->method('getDescription')->willReturn('Source requirement description');

        $targetRequirement = $this->createMock(ComplianceRequirement::class);
        $targetRequirement->method('getCategory')->willReturn('Technical');
        $targetRequirement->method('getDescription')->willReturn('Target requirement description');

        $mapping = $this->createMock(ComplianceMapping::class);
        $mapping->method('getSourceRequirement')->willReturn($sourceRequirement);
        $mapping->method('getTargetRequirement')->willReturn($targetRequirement);
        $mapping->method('getMappingPercentage')->willReturn(70);

        return $mapping;
    }

    private function findGapByType(array $gaps, string $type): ?object
    {
        foreach ($gaps as $gap) {
            if ($gap->getGapType() === $type) {
                return $gap;
            }
        }
        return null;
    }
}
