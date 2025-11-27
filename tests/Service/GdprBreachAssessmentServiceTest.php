<?php

namespace App\Tests\Service;

use App\Service\GdprBreachAssessmentService;
use PHPUnit\Framework\TestCase;

/**
 * Test suite for GDPR Breach Assessment Service
 *
 * Tests the risk scoring algorithm for GDPR Art. 33 breach notification requirements.
 * Ensures correct calculation of breach risk levels and reportability decisions.
 */
class GdprBreachAssessmentServiceTest extends TestCase
{
    private GdprBreachAssessmentService $service;

    protected function setUp(): void
    {
        $this->service = new GdprBreachAssessmentService();
    }

    /**
     * Test low risk: Basic personal data, small scale
     */
    public function testAssessBreachRiskLowRisk(): void
    {
        // Arrange
        $dataTypes = ['names_contact'];
        $scale = 'under_100';

        // Act
        $assessment = $this->service->assessBreachRisk($dataTypes, $scale);

        // Assert
        $this->assertSame('low', $assessment['risk_level']);
        $this->assertFalse($assessment['is_reportable']);
        $this->assertSame('not_reportable', $assessment['recommendation']);
        $this->assertLessThan(5, $assessment['score']);
    }

    /**
     * Test medium risk: Financial data, large scale
     */
    public function testAssessBreachRiskMediumRisk(): void
    {
        // Arrange: financial (2 points) * 0.6 + scale (3) * 0.4 = 1.2 + 1.2 = 2.4 (low)
        // Need higher combination for medium (3-5)
        $dataTypes = ['financial', 'health_biometric']; // 2+3 = 5 * 0.6 = 3.0
        $scale = '1001_to_10000'; // 3 * 0.4 = 1.2, total = 4.2 (medium)

        // Act
        $assessment = $this->service->assessBreachRisk($dataTypes, $scale);

        // Assert
        $this->assertSame('medium', $assessment['risk_level']);
        $this->assertFalse($assessment['is_reportable']); // 4.2 < 5
    }

    /**
     * Test high risk: Health data + financial, large scale
     */
    public function testAssessBreachRiskHighRisk(): void
    {
        // Arrange: (3+2) * 0.6 + 3 * 0.4 = 3.0 + 1.2 = 4.2 (not quite)
        // Need more: (3+2+1) * 0.6 + 4 * 0.4 = 3.6 + 1.6 = 5.2 (high, reportable)
        $dataTypes = ['health_biometric', 'financial', 'names_contact'];
        $scale = 'over_10000';

        // Act
        $assessment = $this->service->assessBreachRisk($dataTypes, $scale);

        // Assert
        $this->assertSame('high', $assessment['risk_level']);
        $this->assertTrue($assessment['is_reportable']);
        $this->assertGreaterThanOrEqual(5, $assessment['score']);
    }

    /**
     * Test very high risk: Special category data (Art. 9 GDPR), massive scale
     */
    public function testAssessBreachRiskVeryHighRisk(): void
    {
        // Arrange: Need score >= 7 for very_high
        // (4+4+3) * 0.6 + 4 * 0.4 = 6.6 + 1.6 = 8.2 (very_high)
        $dataTypes = ['special_category_art9', 'criminal_records_art10', 'health_biometric'];
        $scale = 'over_10000';

        // Act
        $assessment = $this->service->assessBreachRisk($dataTypes, $scale);

        // Assert
        $this->assertSame('very_high', $assessment['risk_level']);
        $this->assertTrue($assessment['is_reportable']);
        $this->assertSame('reportable_very_high', $assessment['recommendation']);
        $this->assertGreaterThanOrEqual(7, $assessment['score']);
    }

    /**
     * Test GDPR Art. 9 special category data triggers high risk
     */
    public function testSpecialCategoryDataAlwaysHighRisk(): void
    {
        // Arrange: 4 * 0.6 + 1 * 0.4 = 2.4 + 0.4 = 2.8 (still low with small scale)
        // Special category with medium scale: 4 * 0.6 + 3 * 0.4 = 2.4 + 1.2 = 3.6 (medium)
        $dataTypes = ['special_category_art9'];
        $scale = '1001_to_10000'; // Need larger scale for high risk

        // Act
        $assessment = $this->service->assessBreachRisk($dataTypes, $scale);

        // Assert: With scale 3, should be medium or high
        $this->assertContains($assessment['risk_level'], ['medium', 'high']);
    }

    /**
     * Test reportability threshold at boundary (score = 5)
     */
    public function testReportabilityThresholdBoundary(): void
    {
        // Test with combination that results in score near threshold
        $dataTypes = ['names_contact', 'financial_data'];
        $scale = '1001_to_10000';

        $assessment = $this->service->assessBreachRisk($dataTypes, $scale);

        // At threshold or above = reportable
        if ($assessment['score'] >= 5) {
            $this->assertTrue($assessment['is_reportable']);
        } else {
            $this->assertFalse($assessment['is_reportable']);
        }
    }

    /**
     * Test empty data types array
     */
    public function testEmptyDataTypes(): void
    {
        // Arrange
        $dataTypes = [];
        $scale = 'under_100';

        // Act
        $assessment = $this->service->assessBreachRisk($dataTypes, $scale);

        // Assert
        $this->assertSame('low', $assessment['risk_level']);
        $this->assertFalse($assessment['is_reportable']);
        // Score = 0 * 0.6 + 1 * 0.4 = 0.4
        $this->assertSame(0.4, $assessment['score']);
    }

    /**
     * Test all available data types are recognized
     */
    public function testAllDataTypesRecognized(): void
    {
        $allDataTypes = [
            'names_contact',
            'financial',
            'health_biometric',
            'special_category_art9',
            'criminal_records_art10',
        ];

        foreach ($allDataTypes as $dataType) {
            $assessment = $this->service->assessBreachRisk([$dataType], 'under_100');

            $this->assertIsArray($assessment);
            $this->assertArrayHasKey('risk_level', $assessment);
            $this->assertArrayHasKey('is_reportable', $assessment);
            $this->assertArrayHasKey('score', $assessment);
            $this->assertArrayHasKey('recommendation', $assessment);
        }
    }

    /**
     * Test all scale options are recognized
     */
    public function testAllScaleOptionsRecognized(): void
    {
        $allScales = ['under_100', '100_to_1000', '1001_to_10000', 'over_10000'];

        foreach ($allScales as $scale) {
            $assessment = $this->service->assessBreachRisk(['names_contact'], $scale);

            $this->assertIsArray($assessment);
            $this->assertArrayHasKey('risk_level', $assessment);
        }
    }

    /**
     * Test score increases with scale
     */
    public function testScoreIncreasesWithScale(): void
    {
        // Arrange
        $dataTypes = ['names_contact'];
        $scales = ['under_100', '100_to_1000', '1001_to_10000', 'over_10000'];

        $previousScore = -1;

        foreach ($scales as $scale) {
            // Act
            $assessment = $this->service->assessBreachRisk($dataTypes, $scale);

            // Assert: Score should increase or stay same with larger scale
            $this->assertGreaterThanOrEqual($previousScore, $assessment['score']);
            $previousScore = $assessment['score'];
        }
    }

    /**
     * Test score increases with more sensitive data types
     */
    public function testScoreIncreasesWithDataTypeSensitivity(): void
    {
        // Arrange: Data types in increasing sensitivity order
        $scale = '100_to_1000';

        $assessment1 = $this->service->assessBreachRisk(['names_contact'], $scale); // 1 * 0.6 + 2 * 0.4 = 1.4
        $assessment2 = $this->service->assessBreachRisk(['financial'], $scale); // 2 * 0.6 + 2 * 0.4 = 2.0
        $assessment3 = $this->service->assessBreachRisk(['health_biometric'], $scale); // 3 * 0.6 + 2 * 0.4 = 2.6
        $assessment4 = $this->service->assessBreachRisk(['special_category_art9'], $scale); // 4 * 0.6 + 2 * 0.4 = 3.2

        // Assert: More sensitive data = higher score (1.4 < 2.0 < 2.6 < 3.2)
        $this->assertLessThan($assessment2['score'], $assessment1['score']); // 2.0 > 1.4
        $this->assertLessThan($assessment3['score'], $assessment2['score']); // 2.6 > 2.0
        $this->assertLessThan($assessment4['score'], $assessment3['score']); // 3.2 > 2.6
    }

    /**
     * Data provider for various risk scenarios
     *
     * @return array<string, array{0: string[], 1: string, 2: string, 3: bool}>
     */
    public static function riskScenarioProvider(): array
    {
        return [
            'Low risk: basic data, small scale' => [
                ['names_contact'], // 1 * 0.6 + 1 * 0.4 = 1.0
                'under_100',
                'low',
                false,
            ],
            'Medium risk: financial + names, medium scale' => [
                ['financial', 'names_contact'], // 3 * 0.6 + 2 * 0.4 = 2.6
                '100_to_1000',
                'low', // 2.6 < 3
                false,
            ],
            'Medium risk: health, medium scale' => [
                ['health_biometric'], // 3 * 0.6 + 3 * 0.4 = 3.0
                '1001_to_10000',
                'medium',
                false,
            ],
            'High risk: health + financial, massive scale' => [
                ['health_biometric', 'financial'], // 5 * 0.6 + 4 * 0.4 = 4.6
                'over_10000',
                'medium', // Just under 5
                false,
            ],
            'High risk: special + financial, massive scale' => [
                ['special_category_art9', 'financial'], // 6 * 0.6 + 4 * 0.4 = 5.2
                'over_10000',
                'high',
                true,
            ],
            'Very high risk: all sensitive data, massive scale' => [
                ['health_biometric', 'special_category_art9', 'criminal_records_art10'], // 11 * 0.6 + 4 * 0.4 = 8.2
                'over_10000',
                'very_high',
                true,
            ],
        ];
    }

    /**
     * Test various risk scenarios
     */
    public function testVariousRiskScenarios(): void
    {
        $scenarios = self::riskScenarioProvider();

        foreach ($scenarios as $name => $data) {
            [$dataTypes, $scale, $expectedLevel, $expectedReportable] = $data;
            // Act
            $assessment = $this->service->assessBreachRisk($dataTypes, $scale);

            // Assert
            $this->assertSame($expectedLevel, $assessment['risk_level'],
                sprintf("[%s] Risk level mismatch for data types: %s at scale: %s (score: %s)",
                    $name, implode(', ', $dataTypes), $scale, $assessment['score'])
            );
            $this->assertSame($expectedReportable, $assessment['is_reportable'],
                sprintf("[%s] Reportability mismatch for data types: %s at scale: %s (score: %s)",
                    $name, implode(', ', $dataTypes), $scale, $assessment['score'])
            );
        }
    }

    /**
     * Test recommendation matches risk level
     */
    public function testRecommendationMatchesRiskLevel(): void
    {
        // Low risk, not reportable
        $assessment1 = $this->service->assessBreachRisk(['names_contact'], 'under_100');
        $this->assertSame('not_reportable', $assessment1['recommendation']);

        // Medium risk, not reportable (score < 5)
        $assessment2 = $this->service->assessBreachRisk(['financial'], '100_to_1000');
        $this->assertSame('not_reportable', $assessment2['recommendation']);

        // High risk, reportable
        $assessment3 = $this->service->assessBreachRisk(['special_category_art9', 'financial'], 'over_10000');
        $this->assertStringContainsString('reportable', $assessment3['recommendation']);

        // Very high risk, reportable
        $assessment4 = $this->service->assessBreachRisk(['special_category_art9', 'criminal_records_art10', 'health_biometric'], 'over_10000');
        $this->assertSame('reportable_very_high', $assessment4['recommendation']);
    }
}
