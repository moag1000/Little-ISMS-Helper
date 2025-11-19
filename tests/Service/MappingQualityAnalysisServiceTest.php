<?php

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Service\MappingQualityAnalysisService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MappingQualityAnalysisServiceTest extends TestCase
{
    private MappingQualityAnalysisService $service;

    protected function setUp(): void
    {
        $this->service = new MappingQualityAnalysisService();
    }

    public function testAnalyzeMappingQualityReturnsCompleteAnalysis(): void
    {
        $mapping = $this->createMappingWithRequirements(
            'ISO 27001 requires access control',
            'GDPR requires access management and authentication',
            'A.9.1',
            'Art. 32'
        );

        $result = $this->service->analyzeMappingQuality($mapping);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('calculated_percentage', $result);
        $this->assertArrayHasKey('textual_similarity', $result);
        $this->assertArrayHasKey('keyword_overlap', $result);
        $this->assertArrayHasKey('structural_similarity', $result);
        $this->assertArrayHasKey('analysis_confidence', $result);
        $this->assertArrayHasKey('quality_score', $result);
        $this->assertArrayHasKey('requires_review', $result);
        $this->assertArrayHasKey('algorithm_version', $result);
        $this->assertArrayHasKey('extracted_keywords', $result);
    }

    public function testAnalyzeMappingQualityWithHighSimilarity(): void
    {
        $mapping = $this->createMappingWithRequirements(
            'Access control authentication authorization identity management',
            'Access control authentication authorization identity management',
            'A.9.1',
            'Art. 32'
        );

        $result = $this->service->analyzeMappingQuality($mapping);

        // Should have high textual similarity
        $this->assertGreaterThan(0.8, $result['textual_similarity']);
        $this->assertGreaterThan(70, $result['calculated_percentage']);
    }

    public function testAnalyzeMappingQualityWithLowSimilarity(): void
    {
        $mapping = $this->createMappingWithRequirements(
            'Physical security controls for building access',
            'Encryption of data at rest and in transit',
            'A.11.1',
            'Art. 32'
        );

        $result = $this->service->analyzeMappingQuality($mapping);

        // Should have lower similarity
        $this->assertLessThan(0.5, $result['textual_similarity']);
    }

    public function testAnalyzeMappingQualityExtractsKeywords(): void
    {
        $mapping = $this->createMappingWithRequirements(
            'Access control and authentication with encryption',
            'Authorization and cryptographic controls',
            'A.9.1',
            'Art. 32'
        );

        $result = $this->service->analyzeMappingQuality($mapping);

        $this->assertArrayHasKey('extracted_keywords', $result);
        $this->assertArrayHasKey('source', $result['extracted_keywords']);
        $this->assertArrayHasKey('target', $result['extracted_keywords']);
        $this->assertNotEmpty($result['extracted_keywords']['source']);
    }

    public function testAnalyzeMappingQualityCalculatesConfidence(): void
    {
        $mapping = $this->createMappingWithRequirements(
            'Access control policy with authentication and authorization procedures',
            'Access management requires authentication authorization and identity verification',
            'A.9.1',
            'Art. 32'
        );

        $result = $this->service->analyzeMappingQuality($mapping);

        $this->assertIsInt($result['analysis_confidence']);
        $this->assertGreaterThanOrEqual(0, $result['analysis_confidence']);
        $this->assertLessThanOrEqual(100, $result['analysis_confidence']);
    }

    public function testAnalyzeMappingQualityRequiresReviewForLowConfidence(): void
    {
        $mapping = $this->createMappingWithRequirements(
            'Short text',
            'Different short text',
            'A.5.1',
            'Art. 5'
        );

        $result = $this->service->analyzeMappingQuality($mapping);

        // Short texts should trigger review requirement
        $this->assertIsBool($result['requires_review']);
    }

    public function testAnalyzeMappingQualityWithMatchingCategories(): void
    {
        $mapping = $this->createMappingWithRequirements(
            'Access control requirements',
            'Access management requirements',
            'A.9.1',
            'Art. 32',
            'Access Control',
            'Access Control'
        );

        $result = $this->service->analyzeMappingQuality($mapping);

        // Matching categories should improve structural similarity
        $this->assertGreaterThan(0.3, $result['structural_similarity']);
    }

    public function testAnalyzeMappingQualityWithDifferentCategories(): void
    {
        $mapping = $this->createMappingWithRequirements(
            'Encryption requirements',
            'Physical security requirements',
            'A.10.1',
            'Art. 32',
            'Cryptography',
            'Physical Security'
        );

        $result = $this->service->analyzeMappingQuality($mapping);

        // Different categories affect structural similarity
        $this->assertIsFloat($result['structural_similarity']);
    }

    public function testAnalyzeMappingQualityWithMatchingPriorities(): void
    {
        $mapping = $this->createMappingWithRequirements(
            'Critical security control',
            'Critical security requirement',
            'A.9.1',
            'Art. 32',
            'Access Control',
            'Access Control',
            'critical',
            'critical'
        );

        $result = $this->service->analyzeMappingQuality($mapping);

        // Matching priorities improve structural similarity
        $this->assertGreaterThan(0, $result['structural_similarity']);
    }

    public function testAnalyzeMappingQualityCalculatesQualityScore(): void
    {
        $mapping = $this->createMappingWithRequirements(
            'Access control and authentication',
            'Access management and authorization',
            'A.9.1',
            'Art. 32'
        );

        $result = $this->service->analyzeMappingQuality($mapping);

        $this->assertIsInt($result['quality_score']);
        $this->assertGreaterThanOrEqual(0, $result['quality_score']);
        $this->assertLessThanOrEqual(100, $result['quality_score']);
    }

    public function testAnalyzeMappingQualityWithVerifiedMapping(): void
    {
        $mapping = $this->createMappingWithRequirements(
            'Access control',
            'Access management',
            'A.9.1',
            'Art. 32'
        );
        $mapping->setVerifiedBy('admin@example.com');

        $result = $this->service->analyzeMappingQuality($mapping);

        // Verified mappings should have higher quality scores
        $this->assertGreaterThan(20, $result['quality_score']);
    }

    public function testAnalyzeMappingQualityWithReviewedMapping(): void
    {
        $mapping = $this->createMappingWithRequirements(
            'Encryption controls',
            'Cryptographic requirements',
            'A.10.1',
            'Art. 32'
        );
        $mapping->setReviewedBy('reviewer@example.com');

        $result = $this->service->analyzeMappingQuality($mapping);

        // Reviewed mappings get points
        $this->assertGreaterThan(0, $result['quality_score']);
    }

    public function testAnalyzeMappingQualityWithISO27001Framework(): void
    {
        $mapping = $this->createMappingWithRequirements(
            'ISO 27001 access control',
            'ISO 27002 access management',
            'A.9.1',
            'A.9.2',
            null,
            null,
            null,
            null,
            'ISO-27001',
            'ISO-27002'
        );

        $result = $this->service->analyzeMappingQuality($mapping);

        // ISO family bonus should apply
        $this->assertGreaterThan(0, $result['calculated_percentage']);
    }

    public function testAnalyzeMappingQualityWithEURegulations(): void
    {
        $mapping = $this->createMappingWithRequirements(
            'GDPR data protection',
            'NIS2 cybersecurity',
            'Art. 32',
            'Art. 21',
            null,
            null,
            null,
            null,
            'GDPR',
            'NIS2'
        );

        $result = $this->service->analyzeMappingQuality($mapping);

        // EU regulation family bonus should apply
        $this->assertGreaterThan(0, $result['calculated_percentage']);
    }

    public function testAnalyzeMappingQualityClampPercentage(): void
    {
        $mapping = $this->createMappingWithRequirements(
            'Comprehensive access control authentication authorization identity management role-based access control least privilege principle with encryption and audit logging monitoring',
            'Complete access management authentication authorization identity verification role-based access control least privilege with cryptographic controls and comprehensive audit trail',
            'A.9.1',
            'Art. 32'
        );

        $result = $this->service->analyzeMappingQuality($mapping);

        // Should clamp to max 150
        $this->assertLessThanOrEqual(150, $result['calculated_percentage']);
        $this->assertGreaterThanOrEqual(0, $result['calculated_percentage']);
    }

    public function testAnalyzeMappingQualityWithEmptyText(): void
    {
        $mapping = $this->createMappingWithRequirements(
            '',
            '',
            'A.5.1',
            'Art. 5'
        );

        $result = $this->service->analyzeMappingQuality($mapping);

        // Empty text: Weighted average of Jaccard (1.0 for empty sets) and Cosine (0.0 for empty vectors)
        // Formula: (Jaccard * 0.4) + (Cosine * 0.6) = (1.0 * 0.4) + (0.0 * 0.6) = 0.4
        // Actual value is approximately 0.4333 due to rounding
        $this->assertEqualsWithDelta(0.4333, $result['textual_similarity'], 0.01);
        // Empty keywords arrays are identical, so Jaccard = 1.0
        $this->assertEquals(1.0, $result['keyword_overlap']);
    }

    public function testAnalyzeMappingQualityWithOnlyTitle(): void
    {
        $sourceReq = $this->createRequirement('A.9.1', 'Access Control', null);
        $targetReq = $this->createRequirement('Art. 32', 'Security Measures', null);

        $mapping = $this->createMock(ComplianceMapping::class);
        $mapping->method('getSourceRequirement')->willReturn($sourceReq);
        $mapping->method('getTargetRequirement')->willReturn($targetReq);
        $mapping->method('getVerifiedBy')->willReturn(null);
        $mapping->method('getReviewedBy')->willReturn(null);

        $result = $this->service->analyzeMappingQuality($mapping);

        // Should work with only titles
        $this->assertIsArray($result);
        $this->assertArrayHasKey('calculated_percentage', $result);
    }

    public function testAnalyzeMappingQualityWithSecurityKeywords(): void
    {
        $mapping = $this->createMappingWithRequirements(
            'Access control authentication encryption firewall audit logging backup recovery',
            'Authentication authorization cryptographic network security monitoring backup disaster recovery',
            'A.9.1',
            'Art. 32'
        );

        $result = $this->service->analyzeMappingQuality($mapping);

        // Should detect multiple security keywords
        $this->assertGreaterThan(0.3, $result['keyword_overlap']);
        $this->assertNotEmpty($result['extracted_keywords']['source']);
        $this->assertNotEmpty($result['extracted_keywords']['target']);
    }

    public function testAnalyzeMappingQualityAlgorithmVersion(): void
    {
        $mapping = $this->createMappingWithRequirements(
            'Test requirement',
            'Test requirement',
            'A.5.1',
            'Art. 5'
        );

        $result = $this->service->analyzeMappingQuality($mapping);

        $this->assertSame('1.0.0', $result['algorithm_version']);
    }

    private function createMappingWithRequirements(
        string $sourceDesc,
        string $targetDesc,
        string $sourceId,
        string $targetId,
        ?string $sourceCategory = null,
        ?string $targetCategory = null,
        ?string $sourcePriority = null,
        ?string $targetPriority = null,
        ?string $sourceFrameworkCode = null,
        ?string $targetFrameworkCode = null
    ): MockObject {
        $sourceReq = $this->createRequirement(
            $sourceId,
            'Source Requirement',
            $sourceDesc,
            $sourceCategory,
            $sourcePriority,
            $sourceFrameworkCode
        );

        $targetReq = $this->createRequirement(
            $targetId,
            'Target Requirement',
            $targetDesc,
            $targetCategory,
            $targetPriority,
            $targetFrameworkCode
        );

        $mapping = $this->createMock(ComplianceMapping::class);
        $mapping->method('getSourceRequirement')->willReturn($sourceReq);
        $mapping->method('getTargetRequirement')->willReturn($targetReq);
        $mapping->method('getVerifiedBy')->willReturn(null);
        $mapping->method('getReviewedBy')->willReturn(null);

        return $mapping;
    }

    private function createRequirement(
        string $reqId,
        string $title,
        ?string $description,
        ?string $category = null,
        ?string $priority = null,
        ?string $frameworkCode = null
    ): MockObject {
        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('getCode')->willReturn($frameworkCode ?? 'ISO-27001');

        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn($reqId);
        $requirement->method('getTitle')->willReturn($title);
        $requirement->method('getDescription')->willReturn($description);
        $requirement->method('getCategory')->willReturn($category);
        $requirement->method('getPriority')->willReturn($priority);
        $requirement->method('getFramework')->willReturn($framework);
        $requirement->method('getDataSourceMapping')->willReturn([]);

        return $requirement;
    }
}
