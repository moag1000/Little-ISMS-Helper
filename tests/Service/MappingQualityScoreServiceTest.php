<?php

namespace App\Tests\Service;

use App\Entity\ComplianceMapping;
use App\Repository\ComplianceMappingRepository;
use App\Service\MappingQualityScoreService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
class MappingQualityScoreServiceTest extends TestCase
{
    private function makeService(array $coverage = ['source_total' => 100, 'source_with_mapping' => 80, 'target_total' => 100, 'target_with_mapping' => 80], float $coherence = 0.9): MappingQualityScoreService
    {
        $repo = $this->createMock(ComplianceMappingRepository::class);
        $repo->method('coverageBetweenFrameworks')->willReturn($coverage);
        $repo->method('reciprocityCoherence')->willReturn($coherence);
        return new MappingQualityScoreService($repo);
    }

    #[Test]
    public function testHighQualityMappingScoresHigh(): void
    {
        $mapping = (new ComplianceMapping())
            ->setProvenanceSource('ENISA Implementation Guidance NIS2 v2024-09')
            ->setProvenanceUrl('https://enisa.europa.eu/...')
            ->setMethodologyType('text_comparison_with_expert_review')
            ->setMethodologyDescription('Volltext-Vergleich + 4-Augen-Review.')
            ->setConfidence('high')
            ->setLifecycleState('published')
            ->setBidirectional(true);

        $svc = $this->makeService();
        $result = $svc->compute($mapping);

        // High provenance (ENISA = official) + high methodology + high confidence + bidirectional + published.
        // Coverage 0 weil keine Framework-Relations im Test → Max ohne Coverage = 85.
        $this->assertGreaterThanOrEqual(80, $result['mqs']);
        $this->assertSame($result['mqs'], $mapping->getQualityScore());
        $this->assertArrayHasKey('provenance', $result['breakdown']);
        $this->assertSame(25, $result['breakdown']['provenance']);
        $this->assertSame(10, $result['breakdown']['lifecycle']);
    }

    #[Test]
    public function testEmptyMappingScoresLow(): void
    {
        $mapping = (new ComplianceMapping())
            ->setLifecycleState('draft');
        $svc = $this->makeService(['source_total' => 0, 'source_with_mapping' => 0, 'target_total' => 0, 'target_with_mapping' => 0], 0.0);
        $result = $svc->compute($mapping);

        $this->assertLessThanOrEqual(20, $result['mqs']);
        $this->assertSame(0, $result['breakdown']['provenance']);
        $this->assertSame(2, $result['breakdown']['lifecycle']);  // draft = 2
    }

    #[Test]
    public function testDeprecatedKillsLifecycleScore(): void
    {
        $mapping = (new ComplianceMapping())
            ->setProvenanceSource('ISO/IEC 27701:2025 Annex D')
            ->setMethodologyType('published_official_mapping')
            ->setMethodologyDescription('Offizielles Annex-D-Mapping.')
            ->setConfidence('high')
            ->setLifecycleState('deprecated')
            ->setBidirectional(true);

        $svc = $this->makeService();
        $result = $svc->compute($mapping);

        $this->assertSame(0, $result['breakdown']['lifecycle']);
        // Selbst mit perfekten anderen Dimensionen <100 wegen lifecycle=0
        $this->assertLessThan(100, $result['mqs']);
    }

    #[Test]
    public function testCommunityMethodologyScoresMidRange(): void
    {
        $mapping = (new ComplianceMapping())
            ->setProvenanceSource('Community Wiki')
            ->setProvenanceUrl('https://github.com/example/...')
            ->setMethodologyType('community_consensus')
            ->setMethodologyDescription('Volunteer-PRs.')
            ->setConfidence('medium')
            ->setLifecycleState('approved');

        $svc = $this->makeService();
        $result = $svc->compute($mapping);

        // Provenance: hat URL aber keine offizielle Indikation → ~17/25
        $this->assertGreaterThanOrEqual(15, $result['breakdown']['provenance']);
        $this->assertLessThanOrEqual(20, $result['breakdown']['provenance']);
        // Methodology community = 8
        $this->assertSame(8, $result['breakdown']['methodology']);
    }

    #[Test]
    public function testConfidenceMappingsScale(): void
    {
        $base = static fn() => (new ComplianceMapping())
            ->setProvenanceSource('BSI-Crosswalk')
            ->setMethodologyType('published_official_mapping')
            ->setMethodologyDescription('Offiziell.')
            ->setLifecycleState('approved');

        $svc = $this->makeService();
        $high = $svc->compute($base()->setConfidence('high'));
        $medium = $svc->compute($base()->setConfidence('medium'));
        $low = $svc->compute($base()->setConfidence('low'));

        $this->assertGreaterThan($medium['breakdown']['confidence'], $high['breakdown']['confidence']);
        $this->assertGreaterThan($low['breakdown']['confidence'], $medium['breakdown']['confidence']);
    }

    #[Test]
    public function testLifecycleScale(): void
    {
        $base = static fn() => (new ComplianceMapping())
            ->setProvenanceSource('BSI-Crosswalk')
            ->setMethodologyType('published_official_mapping')
            ->setMethodologyDescription('Offiziell.')
            ->setConfidence('high');

        $svc = $this->makeService();
        $published = $svc->compute($base()->setLifecycleState('published'));
        $approved = $svc->compute($base()->setLifecycleState('approved'));
        $review = $svc->compute($base()->setLifecycleState('review'));
        $draft = $svc->compute($base()->setLifecycleState('draft'));
        $deprecated = $svc->compute($base()->setLifecycleState('deprecated'));

        $this->assertGreaterThan($approved['breakdown']['lifecycle'], $published['breakdown']['lifecycle']);
        $this->assertGreaterThan($review['breakdown']['lifecycle'], $approved['breakdown']['lifecycle']);
        $this->assertGreaterThan($draft['breakdown']['lifecycle'], $review['breakdown']['lifecycle']);
        $this->assertSame(0, $deprecated['breakdown']['lifecycle']);
    }
}
